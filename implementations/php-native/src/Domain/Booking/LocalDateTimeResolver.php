<?php
declare(strict_types=1);

namespace Talyvane\Domain\Booking;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Resolves destination wall-clock input without silently choosing a DST fold. */
final class LocalDateTimeResolver
{
    /** @return list<array{utc:DateTimeImmutable,offset_seconds:int,label:string}> */
    public static function candidates(string $value,DateTimeZone $zone): array
    {
        if(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value)!==1) {
            throw new InvalidArgumentException('Enter a valid local date and time.');
        }
        $wall=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('UTC'));
        $errors=DateTimeImmutable::getLastErrors();
        if(!$wall || (is_array($errors) && ($errors['warning_count']>0 || $errors['error_count']>0)) ||
            $wall->format('Y-m-d\TH:i')!==$value) {
            throw new InvalidArgumentException('Enter a valid local date and time.');
        }
        $wallTimestamp=$wall->getTimestamp();
        $transitions=$zone->getTransitions($wallTimestamp-259200,$wallTimestamp+259200);
        $offsets=[];
        if(is_array($transitions)) {
            foreach($transitions as $transition) $offsets[(int)$transition['offset']]=true;
        } else {
            $offsets[$zone->getOffset($wall)]=true;
        }
        $matches=[];
        foreach(array_keys($offsets) as $offset) {
            $timestamp=$wallTimestamp-(int)$offset;
            $instant=new DateTimeImmutable('@'.$timestamp);
            if($instant->setTimezone($zone)->format('Y-m-d\TH:i')===$value) {
                $matches[$timestamp]=[
                    'utc'=>$instant,
                    'offset_seconds'=>(int)$offset,
                    'label'=>self::offsetLabel((int)$offset),
                ];
            }
        }
        ksort($matches,SORT_NUMERIC);
        return array_values($matches);
    }

    public static function resolve(string $value,DateTimeZone $zone,?int $selectedOffsetSeconds=null): DateTimeImmutable
    {
        $candidates=self::candidates($value,$zone);
        if($candidates===[]) {
            throw new InvalidArgumentException('That local time does not exist in the destination time zone. Choose another time.');
        }
        if(count($candidates)>1 && $selectedOffsetSeconds===null) {
            throw new AmbiguousLocalTimeException($candidates);
        }
        foreach($candidates as $candidate) {
            if($selectedOffsetSeconds===null || $candidate['offset_seconds']===$selectedOffsetSeconds) {
                return $candidate['utc'];
            }
        }
        throw new InvalidArgumentException('The selected UTC offset is not valid for this destination local time.');
    }

    private static function offsetLabel(int $offset): string
    {
        $sign=$offset<0?'-':'+';
        $absolute=abs($offset);
        return sprintf('UTC%s%02d:%02d',$sign,intdiv($absolute,3600),intdiv($absolute%3600,60));
    }
}
