<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Bookings\BookingQuoteService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;
use Throwable;

final class AdminBookingQuoteController
{
    public function __construct(
        private AuthenticationService $authentication,
        private BookingQuoteService $quotes,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();$actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $options=$this->quotes->adminOptions($actor);
            $items=$this->quotes->adminList($actor);
            return Response::html($this->views->render('admin/booking-quotes',[
                'clients'=>$options['clients'],'profiles'=>$options['profiles'],'items'=>$items,
                'error'=>$error,'notice'=>($request->query['created']??'')==='1'?'The immutable quote has been issued.':'',
                'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,
            ]),$status);
        } catch(DomainException) {
            return Response::html('Forbidden',403);
        }
    }

    public function create(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $expires=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$this->field($request,'expires_at'),new DateTimeZone('UTC'));
            $errors=DateTimeImmutable::getLastErrors();
            if(!$expires instanceof DateTimeImmutable ||
                (is_array($errors) && ($errors['warning_count']>0 || $errors['error_count']>0)) ||
                $expires->format('Y-m-d\TH:i')!==$this->field($request,'expires_at')) {
                throw new InvalidArgumentException('Quote expiry must be a valid UTC date and time.');
            }
            $this->quotes->createFromMajor($actor,$this->field($request,'client_id'),$this->field($request,'profile_id'),
                $this->field($request,'amount'),strtoupper($this->field($request,'currency')),[],$expires,$request->requestId);
            return Response::redirect('/admin/booking-quotes?created=1');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch(Throwable) {
            return $this->index($request,'Quote could not be issued securely. Please try again.',503);
        }
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?trim($value):'';
    }
}
