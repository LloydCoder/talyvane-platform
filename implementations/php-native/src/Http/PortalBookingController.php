<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Bookings\BookingCommandService;
use Talyvane\Application\Bookings\BookingQueryService;
use Talyvane\Application\Bookings\BookingService;
use Talyvane\Application\Discovery\DiscoveryService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class PortalBookingController
{
    public function __construct(
        private AuthenticationService $authentication,
        private BookingService $bookings,
        private BookingCommandService $commands,
        private BookingQueryService $queries,
        private \Talyvane\Application\Bookings\BookingCalendarService $calendar,
        private DiscoveryService $discovery,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function clientIndex(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $client=$this->principal('client');
        if ($client===null) return Response::redirect('/portal/login');
        try {
            return $this->page('client-list',$this->queries->clientHistory($client),$error,$status,[
                'notice'=>($request->query['created']??'')==='1'?'Your booking request has been submitted for review.':((($request->query['rescheduled']??'')==='1')?'Your booking has been rescheduled.':''),
            ]);
        } catch (DomainException) {
            return $this->page('client-list',[],'You do not have permission to view these bookings.',403);
        }
    }

    public function newForm(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        if ($this->principal('client')===null) return Response::redirect('/portal/login');
        $slug=$request->query['profile']??'';
        $profile=is_string($slug)&&$slug!==''?$this->discovery->profile($slug):null;
        $options=[];
        if ($profile===null) {
            foreach ($this->discovery->destinations() as $destination) {
                foreach ($this->discovery->profiles((string)$destination['slug'],48) as $item) $options[]=$item;
            }
        }
        return $this->page('client-new',[],$error,$status,['profile'=>$profile,'profiles'=>$options]);
    }

    public function store(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->newForm($request,'Your session expired. Refresh this page and try again.',419);
        $client=$this->principal('client');
        if ($client===null) return Response::redirect('/portal/login');
        $profile=$this->discovery->profile($this->field($request,'profile_slug'));
        if ($profile===null) return $this->newForm($request,'This profile is not currently available for booking.',422);
        try {
            $zone=new DateTimeZone((string)$profile['time_zone']);
            $start=$this->parseLocalTime($this->field($request,'starts_at'),$zone);
            $end=$this->parseLocalTime($this->field($request,'ends_at'),$zone);
            $this->bookings->request(
                $client,(string)$profile['id'],$start,$end,null,
                $this->nullable($this->field($request,'request_note')),$request->requestId
            );
            return Response::redirect('/portal/bookings?created=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->page('client-new',[],$error->getMessage(),422,['profile'=>$profile,'profiles'=>[]]);
        } catch (\Throwable) {
            return $this->page('client-new',[],'The booking request could not be submitted. Please try again.',503,['profile'=>$profile,'profiles'=>[]]);
        }
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->clientIndex($request,'Your session expired. Refresh this page and try again.',419);
        $client=$this->principal('client');
        if ($client===null) return Response::redirect('/portal/login');
        try {
            $this->commands->transition($client,(string)($params['id']??''),'cancelled',$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/portal/bookings');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->clientIndex($request,$error->getMessage(),422);
        }
    }

    /** @param array<string,string> $params */
    public function rescheduleForm(Request $request,array $params,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $client=$this->principal('client');
        if ($client===null) return Response::redirect('/portal/login');
        try {
            $booking=null;
            foreach ($this->queries->clientHistory($client) as $item) {
                if ((string) $item['id'] === (string) ($params['id'] ?? '')) {
                    $booking=$item;
                    break;
                }
            }
            if (!is_array($booking)) return $this->clientIndex($request,'Booking not found.',404);
            if (!in_array($booking['status'],['accepted','confirmed'],true)) {
                return $this->clientIndex($request,'Only accepted or confirmed bookings can be rescheduled.',422);
            }
            $zone=new DateTimeZone((string) $booking['time_zone']);
            $localStart=(new DateTimeImmutable((string) $booking['starts_at_utc'],new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d\TH:i');
            $localEnd=(new DateTimeImmutable((string) $booking['ends_at_utc'],new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d\TH:i');
            return $this->page('client-reschedule',[],$error,$status,[
                'booking'=>$booking,'localStart'=>$localStart,'localEnd'=>$localEnd,
            ]);
        } catch (DomainException) {
            return $this->clientIndex($request,'You do not have permission to reschedule this booking.',403);
        }
    }

    /** @param array<string,string> $params */
    public function reschedule(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->rescheduleForm($request,$params,'Your session expired. Refresh this page and try again.',419);
        }
        $client=$this->principal('client');
        if ($client===null) return Response::redirect('/portal/login');
        try {
            $booking=null;
            foreach ($this->queries->clientHistory($client) as $item) {
                if ((string) $item['id'] === (string) ($params['id'] ?? '')) {
                    $booking=$item;
                    break;
                }
            }
            if (!is_array($booking) || !in_array($booking['status'],['accepted','confirmed'],true)) {
                return $this->clientIndex($request,'This booking is not available for rescheduling.',422);
            }
            $zone=new DateTimeZone((string) $booking['time_zone']);
            $start=$this->parseLocalTime($this->field($request,'starts_at'),$zone);
            $end=$this->parseLocalTime($this->field($request,'ends_at'),$zone);
            $this->commands->reschedule(
                $client,(string) $booking['id'],$start,$end,
                $this->field($request,'reason'),$request->requestId
            );
            return Response::redirect('/portal/bookings?rescheduled=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->rescheduleForm($request,$params,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->rescheduleForm($request,$params,'The booking could not be rescheduled. Please try again.',503);
        }
    }

    public function clientCalendar(Request $request): Response
    {
        return $this->calendarPage('client');
    }

    public function companionCalendar(Request $request): Response
    {
        return $this->calendarPage('companion');
    }

    public function clientCalendarIcs(Request $request): Response
    {
        return $this->calendarDownload('client');
    }

    public function companionCalendarIcs(Request $request): Response
    {
        return $this->calendarDownload('companion');
    }

    private function calendarPage(string $role): Response
    {
        SessionManager::start();
        $actor=$this->principal($role);
        if ($actor===null) return Response::redirect('/portal/login');
        try {
            $events=$role==='client'?$this->calendar->clientEvents($actor):$this->calendar->companionEvents($actor);
            return Response::html($this->views->render('portal/calendar',[
                'events'=>$events,'role'=>$role,'brandName'=>$this->brandName,
            ]));
        } catch (DomainException) {
            return Response::html('You do not have permission to view this calendar.',403);
        }
    }

    private function calendarDownload(string $role): Response
    {
        SessionManager::start();
        $actor=$this->principal($role);
        if ($actor===null) return Response::redirect('/portal/login');
        try {
            $events=$role==='client'?$this->calendar->clientEvents($actor):$this->calendar->companionEvents($actor);
            $body=$this->calendar->toIcalendar($actor,$events,new DateTimeImmutable('now',new DateTimeZone('UTC')));
            $filename=$role==='client'?'talyvane-client-calendar.ics':'talyvane-companion-calendar.ics';
            return new Response($body,200,[
                'content-type'=>'text/calendar; charset=utf-8',
                'content-disposition'=>'attachment; filename="' . $filename . '"',
                'cache-control'=>'no-store, private',
                'pragma'=>'no-cache',
                'x-content-type-options'=>'nosniff',
            ]);
        } catch (DomainException) {
            return Response::html('You do not have permission to export this calendar.',403);
        }
    }

    public function companionIndex(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $companion=$this->principal('companion');
        if ($companion===null) return Response::redirect('/portal/login');
        try {
            return $this->page('companion-list',$this->queries->companionHistory($companion),$error,$status);
        } catch (DomainException) {
            return $this->page('companion-list',[],'You do not have permission to view these bookings.',403);
        }
    }

    /** @param array<string,string> $params */
    public function companionDecision(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->companionIndex($request,'Your session expired. Refresh this page and try again.',419);
        $companion=$this->principal('companion');
        if ($companion===null) return Response::redirect('/portal/login');
        $decision=$this->field($request,'decision');
        if (!in_array($decision,['accepted','declined'],true)) return $this->companionIndex($request,'Choose accept or decline.',422);
        try {
            $this->commands->transition($companion,(string)($params['id']??''),$decision,$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/portal/companion/bookings');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->companionIndex($request,$error->getMessage(),422);
        }
    }

    public function adminIndex(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if ($actor===null) return Response::redirect('/admin/login');
        try {
            return $this->page('admin-list',$this->queries->adminQueue($actor),$error,$status);
        } catch (DomainException) {
            return $this->page('admin-list',[],'You do not have permission to manage bookings.',403);
        }
    }

    /** @param array<string,string> $params */
    public function adminTransition(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->adminIndex($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if ($actor===null) return Response::redirect('/admin/login');
        try {
            $this->commands->transition($actor,(string)($params['id']??''),$this->field($request,'status'),$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/admin/bookings');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->adminIndex($request,$error->getMessage(),422);
        }
    }

    private function principal(string $role): ?Principal
    {
        $principal=$this->authentication->currentPrincipal();
        if ($principal===null || count(array_intersect($principal->roles,['client','companion']))!==1 || !$principal->hasRole($role)) return null;
        return $principal;
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?$value:'';
    }

    private function parseLocalTime(string $value,DateTimeZone $zone): DateTimeImmutable
    {
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i',$value,$zone);
        $errors=DateTimeImmutable::getLastErrors();
        if (!$parsed instanceof DateTimeImmutable ||
            (is_array($errors) && ($errors['warning_count']>0 || $errors['error_count']>0)) ||
            $parsed->format('Y-m-d\\TH:i') !== $value) {
            // PHP otherwise normalizes a wall-clock time inside a DST spring-forward
            // gap (for example 02:30 becomes 03:30). Reject it instead of silently
            // booking a different local time than the client explicitly selected.
            throw new InvalidArgumentException('That local time does not exist in the destination time zone. Choose another time.');
        }
        return $parsed;
    }

    private function nullable(string $value): ?string
    {
        $value=trim($value);
        return $value===''?null:$value;
    }

    /** @param list<array<string,mixed>> $items @param array<string,mixed> $extra */
    private function page(string $mode,array $items,string $error,int $status,array $extra=[]): Response
    {
        $data=array_replace([
            'mode'=>$mode,'items'=>$items,'error'=>$error,'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,
            'notice'=>'','profile'=>null,'profiles'=>[],'booking'=>null,'localStart'=>'','localEnd'=>'',
        ],$extra);
        return Response::html($this->views->render('portal/bookings',$data),$status);
    }
}
