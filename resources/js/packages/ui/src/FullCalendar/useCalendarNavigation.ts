import { computed, ref } from 'vue';
import type { Dayjs } from 'dayjs';
import { getLocalizedDayJs } from '../utils/time';
import { getWeekStartDayNumber } from '../utils/settings';
import { getDateRangeLabel } from '../utils/dateRange';

export type CalendarView = 'timeGridWeek' | 'timeGridDay' | 'month' | 'year';

export function getCalendarMonthDays(date: Dayjs, fixedLength = false): Dayjs[] {
    const first = date.startOf('month');
    const offset = (first.day() - getWeekStartDayNumber() + 7) % 7;
    const days = fixedLength ? 42 : Math.ceil((offset + first.daysInMonth()) / 7) * 7;
    return Array.from({ length: days }, (_, index) => first.add(index - offset, 'day'));
}

export function useCalendarNavigation(callbacks: {
    onDatesChange: (payload: { start: Dayjs; end: Dayjs; view: CalendarView }) => void;
    scrollToCurrentTime: () => void;
    initialDate?: Dayjs | null;
    initialView?: CalendarView;
}) {
    const activeView = ref<CalendarView>(callbacks.initialView ?? 'timeGridWeek');
    const currentDate = ref(callbacks.initialDate ?? getLocalizedDayJs());
    const isOverview = computed(() => activeView.value === 'month' || activeView.value === 'year');

    const viewDays = computed<Dayjs[]>(() => {
        const numDays = activeView.value === 'timeGridWeek' ? 7 : 1;

        if (numDays === 1) {
            return [currentDate.value.startOf('day')];
        }

        const firstDay = getWeekStartDayNumber();
        const today = currentDate.value.startOf('day');
        const offset = (today.day() - firstDay + 7) % 7;
        const weekStart = today.subtract(offset, 'day');

        const days: Dayjs[] = [];
        for (let i = 0; i < numDays; i++) {
            days.push(weekStart.add(i, 'day'));
        }
        return days;
    });

    const rangeLabel = computed(() => {
        if (activeView.value === 'year') return currentDate.value.format('YYYY');
        if (activeView.value === 'month') return currentDate.value.format('MMMM YYYY');
        const days = viewDays.value;
        if (days.length === 0) return '';
        return getDateRangeLabel(days[0]!, days[days.length - 1]!);
    });

    function emitDatesChange() {
        const view = activeView.value;
        if (view === 'year') {
            const start = currentDate.value.startOf('year');
            callbacks.onDatesChange({ start, end: start.add(1, 'year'), view });
            return;
        }
        const days = view === 'month' ? getCalendarMonthDays(currentDate.value) : viewDays.value;
        callbacks.onDatesChange({
            start: days[0]!,
            end: days[days.length - 1]!.add(1, 'day'),
            view,
        });
    }

    function navigate(direction: number) {
        const view = activeView.value;
        const unit = view === 'year' ? 'year' : view === 'month' ? 'month' : 'day';
        currentDate.value = currentDate.value.add(
            view === 'timeGridWeek' ? direction * 7 : direction,
            unit
        );
        emitDatesChange();
        callbacks.scrollToCurrentTime();
    }

    function handleToday() {
        currentDate.value = getLocalizedDayJs();
        emitDatesChange();
        callbacks.scrollToCurrentTime();
    }

    function handleChangeView(view: CalendarView, date?: Dayjs) {
        if (date) currentDate.value = date;
        activeView.value = view;
        emitDatesChange();
        callbacks.scrollToCurrentTime();
    }

    return {
        activeView,
        currentDate,
        isOverview,
        viewDays,
        rangeLabel,
        emitDatesChange,
        handlePrev: () => navigate(-1),
        handleNext: () => navigate(1),
        handleToday,
        handleChangeView,
    };
}
