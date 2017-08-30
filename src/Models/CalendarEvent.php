<?php

namespace T1k3\LaravelCalendarEvent\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use T1k3\LaravelCalendarEvent\Exceptions\InvalidMonthException;

/**
 * Class CalendarEvent
 * @package T1k3\LaravelCalendarEvent\Models
 */
class CalendarEvent extends AbstractModel
{
    use SoftDeletes;

    /**
     * Fillable
     * @var array
     */
    protected $fillable = [
        'start_date'
    ];

    /**
     * Attribute Casting
     * @var array
     */
    protected $casts = [
        'start_date' => 'date'
    ];

    /**
     * TemplateCalendarEvent
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function template()
    {
        return $this->belongsTo(TemplateCalendarEvent::class, 'template_calendar_event_id');
    }

    /**
     * Calendar event (template) data - input is different
     * @param array $attributes
     * @return bool
     */
    public function dataIsDifferent(array $attributes): bool
    {
        if (isset($attributes['start_date'])) {
            if ($this->start_date->format('Y-m-d') !== $attributes['start_date']) {
                return true;
            }
            unset($attributes['start_date']);
        }

        $template = $this->template;
        foreach ($attributes as $key => $value) {
            if ($template->{$key} !== $value) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array $attributes
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function createCalendarEvent(array $attributes)
    {
        DB::transaction(function () use ($attributes, &$calendarEvent) {
            $templateCalendarEvent = $this->template()->create($attributes);
            $calendarEvent         = $this->make($attributes);
            $calendarEvent->template()->associate($templateCalendarEvent);
            $calendarEvent->save();
        });

        return $calendarEvent;
    }

    /**
     * @param array $attributes
     * @return null|CalendarEvent
     */
    public function editCalendarEvent(array $attributes)
    {
        if ($this->dataIsDifferent($attributes)) {
            $calendarEventNew = $this->createCalendarEvent(
                array_merge(
                    $this->template->toArray(),
                    ['start_date' => $this->start_date],
                    $attributes
                )
            );

            $calendarEventNew->template->parent()->associate($this->template);
            if ($this->template->is_recurring && $calendarEventNew->template->is_recurring) {
                $this->template->update([
                    'end_of_recurring' => $this->start_date
                ]);
            } else {
                $calendarEventNew->template->update([
                    'end_of_recurring' => null
                ]);
            }
            $this->delete();

            return $calendarEventNew;
        }
        return null;
    }

    /**
     * Show all events in month
     * @param int $month
     * @return \Illuminate\Support\Collection|static
     * @throws InvalidMonthException
     */
    public static function showPotentialCalendarEventsOfMonth(int $month)
    {
//        TODO Refactor | This is NOT a good solution (but working)
        if ($month < 1 || $month > 12) {
            throw new InvalidMonthException();
        }

        $month                  = str_pad($month, 2, '0', STR_PAD_LEFT);
        $end_of_recurring       = Carbon::parse(date('Y-' . $month))->lastOfMonth()->format('Y-m-d');
        $templateCalendarEvents = TemplateCalendarEvent
            ::where(function ($q) use ($month) {
                $q->where('is_recurring', false)
                    ->whereMonth('start_date', $month);
            })
            ->orWhere(function ($q) use ($end_of_recurring) {
                $q->where('is_recurring', true)
                    ->whereNull('end_of_recurring')
                    ->where('start_date', '<=', $end_of_recurring);
            })
            ->orWhere(function ($q) use ($end_of_recurring) {
                $q->where('is_recurring', true)
                    ->where('start_date', '<=', $end_of_recurring)
                    ->whereMonth('end_of_recurring', '<=', $end_of_recurring);
            })
            ->with('events')
            ->get();

        $calendarEvents = collect();
        foreach ($templateCalendarEvents as $templateCalendarEvent) {
            $calendarEvents       = $calendarEvents->merge($templateCalendarEvent->events()->whereMonth('start_date', $month)->get());
            $dateNext             = null;
            $calendarEventTmpLast = $templateCalendarEvent->events()->orderBy('start_date', 'desc')->first();
            if ($calendarEventTmpLast) {
                $dateNext = $templateCalendarEvent->getNextCalendarEventStartDate($calendarEventTmpLast->start_date);
            }

            while ($dateNext !== null && $dateNext->month <= (int)$month) {
                $calendarEventNotExist               = (new CalendarEvent())->make(['start_date' => $dateNext]);
                $calendarEventNotExist->is_not_exist = true;
                $calendarEventNotExist->template()->associate($templateCalendarEvent);
                if ($calendarEventNotExist->start_date->month === (int)$month) {
                    $calendarEvents = $calendarEvents->merge(collect([$calendarEventNotExist]));
                }

                $dateNext = $templateCalendarEvent->getNextCalendarEventStartDate($calendarEventNotExist->start_date);
            }
        }

        return $calendarEvents;
    }
}
