<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use JsonException;
use RuntimeException;

/** HashiCorp Vault Transit provider with separate runtime and retention credentials. */
final class VaultTransitSafeguardingCaseKeyProvider implements SafeguardingCaseKeyProvider
{
    /** @param array<string,mixed> $config */
    public function __construct(private array $config)
    {
        $this->endpoint();
        $this->keyNamespace();
        $this->runtimeToken();
        $this->retentionToken();
    }

    public function name(): string { return 'vault-transit'; }
    public function supportsDestruction(): bool { return true; }

    public function wrap(string $caseId,string $caseKey): string
    {
        if (!preg_match('/^[0-9a-f-]{36}$/D',$caseId) || strlen($caseKey)!==32) {
            throw new RuntimeException('Safeguarding case key material is invalid.');
        }
        $keyName=$this->casePrefix().bin2hex(random_bytes(16));
        $created=$this->request('POST','keys/'.$keyName,[
            'type'=>'aes256-gcm96','exportable'=>false,'allow_plaintext_backup'=>false,
        ],$this->runtimeToken());
        if ($created['status']<200 || $created['status']>=300) {
            throw new RuntimeException('External safeguarding key creation failed.');
        }
        $encrypted=$this->request('POST','encrypt/'.$keyName,['plaintext'=>base64_encode($caseKey)],$this->runtimeToken());
        $ciphertext=$encrypted['json']['data']['ciphertext']??null;
        if ($encrypted['status']<200 || $encrypted['status']>=300 || !is_string($ciphertext) || !str_starts_with($ciphertext,'vault:v')) {
            throw new RuntimeException('External safeguarding key encryption failed.');
        }
        return 'vault1.'.base64_encode(json_encode(['key_name'=>$keyName,'ciphertext'=>$ciphertext],JSON_THROW_ON_ERROR));
    }

    public function unwrap(string $caseId,string $wrappedKey): string
    {
        $envelope=$this->decodeEnvelope($caseId,$wrappedKey);
        $response=$this->request('POST','decrypt/'.$envelope['key_name'],['ciphertext'=>$envelope['ciphertext']],$this->runtimeToken());
        $encoded=$response['json']['data']['plaintext']??null;
        $plaintext=is_string($encoded)?base64_decode($encoded,true):false;
        if ($response['status']<200 || $response['status']>=300 || !is_string($plaintext) || strlen($plaintext)!==32) {
            throw new RuntimeException('External safeguarding key decryption failed.');
        }
        return $plaintext;
    }

    public function destroy(string $caseId,string $wrappedKey): void
    {
        $envelope=$this->decodeEnvelope($caseId,$wrappedKey);
        $token=$this->retentionToken();
        $this->assertDistinctCredentials();
        $configured=$this->request('POST','keys/'.$envelope['key_name'].'/config',['deletion_allowed'=>true],$token);
        if ($configured['status']===404) { return; }
        if ($configured['status']<200 || $configured['status']>=300) {
            throw new RuntimeException('External safeguarding key deletion could not be authorized.');
        }
        $deleted=$this->request('DELETE','keys/'.$envelope['key_name'],null,$token);
        if (($deleted['status']<200 || $deleted['status']>=300) && $deleted['status']!==404) {
            throw new RuntimeException('External safeguarding key destruction failed.');
        }
    }

    public function verify(): void
    {
        $caseId='00000000-0000-4000-8000-000000000001';
        $key=random_bytes(32);
        $wrapped=$this->wrap($caseId,$key);
        try {
            $roundTrip=$this->unwrap($caseId,$wrapped);
            if (!hash_equals($key,$roundTrip)) {
                throw new RuntimeException('External safeguarding key round-trip verification failed.');
            }
        } finally {
            $this->destroy($caseId,$wrapped);
            if (function_exists('sodium_memzero')) { sodium_memzero($key); }
            if (isset($roundTrip) && function_exists('sodium_memzero')) { sodium_memzero($roundTrip); }
        }
    }

    /** @param array<string,string> $activeCaseKeys @return array{active_keys_verified:int,orphan_keys_destroyed:int} */
    public function reconcile(array $activeCaseKeys): array
    {
        $this->assertDistinctCredentials();
        $active=[];
        foreach ($activeCaseKeys as $caseId=>$wrapped) {
            $envelope=$this->decodeEnvelope((string)$caseId,$wrapped);
            $plain=$this->unwrap((string)$caseId,$wrapped);
            if (function_exists('sodium_memzero')) { sodium_memzero($plain); }
            $active[$envelope['key_name']]=$wrapped;
        }
        $inventory=$this->request('LIST','keys',null,$this->retentionToken());
        $remote=$inventory['json']['data']['keys']??null;
        if ($inventory['status']<200 || $inventory['status']>=300 || !is_array($remote)) {
            throw new RuntimeException('External safeguarding key inventory could not be verified.');
        }
        $managed=array_values(array_filter($remote,fn($key):bool=>is_string($key)&&str_starts_with($key,$this->casePrefix())));
        $orphans=array_values(array_diff($managed,array_keys($active)));
        foreach ($orphans as $keyName) { $this->destroyKeyName($keyName); }
        $after=$this->request('LIST','keys',null,$this->retentionToken());
        $remaining=$after['json']['data']['keys']??null;
        if ($after['status']<200 || $after['status']>=300 || !is_array($remaining)) {
            throw new RuntimeException('External safeguarding key inventory could not be verified after reconciliation.');
        }
        $remainingManaged=array_values(array_filter($remaining,fn($key):bool=>is_string($key)&&str_starts_with($key,$this->casePrefix())));
        if (array_diff(array_keys($active),$remainingManaged)!==[] || array_diff($remainingManaged,array_keys($active))!==[]) {
            throw new RuntimeException('Safeguarding Vault reconciliation did not reach the expected key inventory.');
        }
        return ['active_keys_verified'=>count($active),'orphan_keys_destroyed'=>count($orphans)];
    }

    private function destroyKeyName(string $keyName): void
    {
        if (!preg_match('/^'.preg_quote($this->casePrefix(),'/').'[a-f0-9]{32}$/D',$keyName)) {
            throw new RuntimeException('External safeguarding key reference belongs to another deployment.');
        }
        $token=$this->retentionToken();
        $config=$this->request('POST','keys/'.$keyName.'/config',['deletion_allowed'=>true],$token);
        if ($config['status']===404) { return; }
        if ($config['status']<200 || $config['status']>=300) { throw new RuntimeException('External safeguarding key deletion could not be authorized.'); }
        $delete=$this->request('DELETE','keys/'.$keyName,null,$token);
        if (($delete['status']<200 || $delete['status']>=300) && $delete['status']!==404) {
            throw new RuntimeException('External safeguarding key destruction failed.');
        }
    }

    /** @return array{key_name:string,ciphertext:string} */
    private function decodeEnvelope(string $caseId,string $wrappedKey): array
    {
        if (!str_starts_with($wrappedKey,'vault1.')) {
            throw new RuntimeException('External safeguarding key envelope is invalid.');
        }
        $json=base64_decode(substr($wrappedKey,7),true);
        $data=is_string($json)?json_decode($json,true,8):null;
        $keyName=is_array($data)?($data['key_name']??null):null;
        $ciphertext=is_array($data)?($data['ciphertext']??null):null;
        if (!is_string($keyName) || !is_string($ciphertext) || !str_starts_with($ciphertext,'vault:v') ||
            !preg_match('/^'.preg_quote($this->casePrefix(),'/').'[a-f0-9]{32}$/D',$keyName)) {
            throw new RuntimeException('External safeguarding key reference is invalid or belongs to another deployment.');
        }
        return ['key_name'=>$keyName,'ciphertext'=>$ciphertext];
    }

    private function endpoint(): string
    {
        $address=rtrim(trim((string)($this->config['address']??'')),'/');
        $parts=parse_url($address);
        if (!is_array($parts) || ($parts['scheme']??null)!=='https' || empty($parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Vault Transit requires a valid HTTPS endpoint without embedded credentials or query parameters.');
        }
        return $address;
    }

    private function mount(): string
    {
        $mount=trim((string)($this->config['mount']??'transit'),'/');
        if (!preg_match('/^[A-Za-z0-9_-]+$/D',$mount)) { throw new RuntimeException('Vault Transit mount name is invalid.'); }
        return $mount;
    }

    private function keyNamespace(): string
    {
        $value=strtolower(trim((string)($this->config['namespace']??'')));
        if (!preg_match('/^[a-z0-9]{8,32}$/D',$value)) {
            throw new RuntimeException('A unique 8–32 character safeguarding Vault namespace is required.');
        }
        return $value;
    }

    private function casePrefix(): string { return 'case-'.$this->keyNamespace().'-'; }

    private function runtimeToken(): string
    {
        $token=trim((string)($this->config['token']??''));
        if ($token==='' || preg_match('/[\r\n]/',$token)) { throw new RuntimeException('Vault runtime token is not configured.'); }
        return $token;
    }

    private function retentionToken(): string
    {
        $token=trim((string)($this->config['retention_token']??''));
        if ($token==='' || preg_match('/[\r\n]/',$token)) { throw new RuntimeException('A separate Vault retention token is required.'); }
        return $token;
    }

    private function assertDistinctCredentials(): void
    {
        if (hash_equals($this->runtimeToken(),$this->retentionToken())) {
            throw new RuntimeException('Vault runtime and retention credentials must be distinct.');
        }
    }

    /** @param array<string,mixed>|null $payload @return array{status:int,json:array<string,mixed>} */
    private function request(string $method,string $operation,?array $payload,string $token): array
    {
        $url=$this->endpoint().'/v1/'.$this->mount().'/'.$operation;
        $headers="Accept: application/json\r\nX-Vault-Token: ".$token."\r\n";
        $content='';
        if ($payload!==null) {
            $headers.="Content-Type: application/json\r\n";
            $content=json_encode($payload,JSON_THROW_ON_ERROR);
        }
        if (isset($this->config['vault_namespace']) && trim((string)$this->config['vault_namespace'])!=='') {
            $ns=trim((string)$this->config['vault_namespace']);
            if (preg_match('/[\r\n]/',$ns)) { throw new RuntimeException('Vault namespace header is invalid.'); }
            $headers.="X-Vault-Namespace: ".$ns."\r\n";
        }
        $context=stream_context_create([
            'http'=>[
                'method'=>$method,'header'=>$headers,'content'=>$content,'timeout'=>max(1,min(15,(int)($this->config['timeout']??5))),
                'ignore_errors'=>true,'follow_location'=>0,'max_redirects'=>0,
            ],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false],
        ]);
        $response=@file_get_contents($url,false,$context);
        $status=0;
        foreach (($http_response_header??[]) as $header) {
            if (preg_match('/^HTTP\/\S+\s+([0-9]{3})/',$header,$match)) { $status=(int)$match[1]; break; }
        }
        if (!is_string($response)) { throw new RuntimeException('Vault Transit request failed; verify HTTPS connectivity and credentials.'); }
        try { $decoded=json_decode($response,true,16,JSON_THROW_ON_ERROR); }
        catch (JsonException) { $decoded=[]; }
        return ['status'=>$status,'json'=>is_array($decoded)?$decoded:[]];
    }
}
