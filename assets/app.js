
document.addEventListener('DOMContentLoaded', () => {

    const calendarEl = document.getElementById('calendar');

    if (!calendarEl) return;

    const dialog = document.getElementById('leaveDialog');
    const form = document.getElementById('leaveForm');
    const mode = document.getElementById('calculationMode');
    const hoursWrap = document.getElementById('hoursWrap');
    const calculationWrap = document.getElementById('calculationWrap');
    const errorEl = document.getElementById('formError');
    const deleteButton = document.getElementById('deleteLeave');

    const entryType = document.getElementById('entryType');

    const leaveFields = document.getElementById('leaveFields');
    const creditFields = document.getElementById('creditFields');

    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');
    const startTimeInput = document.getElementById('startTime');
    const endTimeInput = document.getElementById('endTime');

    const creditDateInput = document.getElementById('creditDate');
    const creditMinutesInput = document.getElementById('creditMinutes');

    const ANNUAL_ALLOWANCE = 193.75;

    let calendar;

    const localDate = (date) => {

        const d = new Date(date);

        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    };

    const formatHours = (value) => {

        return Number(value).toLocaleString('nl-NL', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });

    };

    async function request(url, options = {}) {

        const response = await fetch(url, options);

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.error || 'Er ging iets mis.');
        }

        return data;

    }

    async function updateBalance(year) {

        try {

            const data = await request(
                `api.php?balance_year=${encodeURIComponent(year)}`
            );

            const availableHours = document.getElementById('availableHours');
            const usedHours = document.getElementById('usedHours');
            const remainingHours = document.getElementById('remainingHours');

            const availableDays = document.getElementById('availableDays');
            const usedDays = document.getElementById('usedDays');
            const remainingDays = document.getElementById('remainingDays');

            const remainingWrapper =
                document.getElementById('remainingHoursWrapper');

            if (
                !availableHours ||
                !usedHours ||
                !remainingHours ||
                !availableDays ||
                !usedDays ||
                !remainingDays
            ) {
                return;
            }

            const totalAvailable = ANNUAL_ALLOWANCE;

            const used = Number(data.used);
            const remaining = Number(data.remaining);
            const hoursPerDay = Number(data.hours_per_day);

            if (
                !Number.isFinite(used) ||
                !Number.isFinite(remaining) ||
                !Number.isFinite(hoursPerDay) ||
                hoursPerDay <= 0
            ) {
                throw new Error('Ongeldige saldogegevens ontvangen.');
            }

            availableHours.textContent =
                formatHours(totalAvailable);

            usedHours.textContent =
                formatHours(used);

            remainingHours.textContent =
                formatHours(remaining);

            availableDays.textContent =
                formatHours(totalAvailable / hoursPerDay);

            usedDays.textContent =
                formatHours(used / hoursPerDay);

            remainingDays.textContent =
                formatHours(remaining / hoursPerDay);

            if (remainingWrapper) {

                remainingWrapper.classList.toggle(
                    'negative',
                    remaining < 0
                );

            }

        } catch (err) {

            console.error('Saldo ophalen mislukt:', err);

        }

    }

    // Weergave aanpassen aan het soort boeking.
    const setEntryTypeUI = () => {

        const type = entryType.value;

        const isCredit = type === 'time_credit';
        const isSick = type === 'sick';
        const isAppointment = type === 'doctor' || type === 'dentist';

        const isNonLeave = isCredit || isSick || isAppointment;

        leaveFields.classList.toggle('hidden', isCredit);
        creditFields.classList.toggle('hidden', !isCredit);

        startDateInput.required = !isCredit;
        endDateInput.required = !isCredit;
        startTimeInput.required = !isCredit;
        endTimeInput.required = !isCredit;

        creditDateInput.required = isCredit;
        creditMinutesInput.required = isCredit;

        calculationWrap.classList.toggle('hidden', isNonLeave);

        if (isNonLeave) {
            mode.value = 'automatic';
        }

        mode.disabled = isNonLeave;

        hoursWrap.classList.toggle(
            'hidden',
            isNonLeave || mode.value !== 'manual'
        );

        const titles = {
            leave: 'Verlof inplannen',
            sick: 'Ziekte registreren',
            time_credit: 'Tijd-voor-tijd toevoegen',
            doctor: 'Doktersafspraak registreren',
            dentist: 'Tandartsafspraak registreren'
        };

        document.getElementById('dialogTitle').textContent =
            titles[type] || 'Boeking toevoegen';

    };

    // Handmatige berekening tonen of verbergen.
    const setModeUI = () => {

        const isNonLeave =
            entryType.value === 'sick' ||
            entryType.value === 'doctor' ||
            entryType.value === 'dentist';

        hoursWrap.classList.toggle(
            'hidden',
            isNonLeave || mode.value !== 'manual'
        );

    };

    entryType.addEventListener('change', setEntryTypeUI);
    mode.addEventListener('change', setModeUI);

    // Nieuwe boeking openen.
    function openNew(start, end) {

        form.reset();

        document.getElementById('leaveId').value = '';

        entryType.value = 'leave';

        const today = localDate(new Date());

        startDateInput.value = start || today;
        endDateInput.value = end || start || today;

        creditDateInput.value = start || today;

        startTimeInput.value = '08:45';
        endTimeInput.value = '16:30';

        creditMinutesInput.value = 15;

        const employee = document.getElementById('employeeId');

        if (employee && !window.APP_CONFIG.isAdmin) {
            employee.value = window.APP_CONFIG.currentUserId;
        }

        mode.value = 'automatic';

        setModeUI();
        setEntryTypeUI();

        errorEl.textContent = '';

        deleteButton.classList.add('hidden');

        dialog.showModal();

    }

    // Bestaande boeking aanpassen.
    function openEdit(event) {

        const p = event.extendedProps || {};

        if (p.holiday) return;

        form.reset();

        document.getElementById('leaveId').value = event.id;

        if (p.entry_type === 'time_credit') {

            entryType.value = 'time_credit';

            creditDateInput.value = event.startStr.slice(0, 10);

            creditMinutesInput.value = p.minutes || 15;

        } else {

            entryType.value = [
                'leave',
                'sick',
                'doctor',
                'dentist'
            ].includes(p.entry_type)
                ? p.entry_type
                : 'leave';

            startDateInput.value = event.startStr.slice(0, 10);

            endDateInput.value = event.end
                ? localDate(new Date(event.end.getTime() - 86400000))
                : event.startStr.slice(0, 10);

            startTimeInput.value = p.start_time
                ? p.start_time.substring(0, 5)
                : '08:45';

            endTimeInput.value = p.end_time
                ? p.end_time.substring(0, 5)
                : '16:30';

            mode.value = p.mode || 'automatic';

            document.getElementById('hours').value = p.hours || 0;

        }

        document.getElementById('note').value = p.note || '';

        const employee = document.getElementById('employeeId');

        if (employee && p.user_id) {
            employee.value = p.user_id;
        }

        setModeUI();
        setEntryTypeUI();

        errorEl.textContent = '';

        const titles = {
            leave: 'Verlof aanpassen',
            sick: 'Ziekte aanpassen',
            time_credit: 'Tijd-voor-tijd aanpassen',
            doctor: 'Doktersafspraak aanpassen',
            dentist: 'Tandartsafspraak aanpassen'
        };

        document.getElementById('dialogTitle').textContent =
            titles[p.entry_type] || 'Boeking aanpassen';

        deleteButton.classList.remove('hidden');

        dialog.showModal();

    }

    // Kalender aanmaken.
    calendar = new FullCalendar.Calendar(calendarEl, {

        locale: 'nl',
        firstDay: 1,
        initialView: 'dayGridMonth',
        height: 'auto',

        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listMonth'
        },

        buttonText: {
            today: 'Vandaag',
            month: 'Maand',
            week: 'Week',
            list: 'Lijst'
        },

        selectable: true,
        selectMirror: true,

        datesSet: (info) => {

            const selectedYear =
                info.view.calendar.getDate().getFullYear();

            updateBalance(selectedYear);

        },

        events: async (info, success, failure) => {

            try {

                const events = await request(
                    `api.php?start=${encodeURIComponent(info.startStr.slice(0, 10))}&end=${encodeURIComponent(info.endStr.slice(0, 10))}`
                );

                success(events);

            } catch (err) {

                failure(err);

            }

        },

        select: (info) => {

            const end = new Date(info.end);

            end.setDate(end.getDate() - 1);

            openNew(
                info.startStr.slice(0, 10),
                localDate(end)
            );

            calendar.unselect();

        },

        dateClick: (info) => {

            openNew(info.dateStr, info.dateStr);

        },

        eventClick: (info) => {

            if (info.event.extendedProps.holiday) return;

            openEdit(info.event);

        },

        eventDidMount: (info) => {

            if (info.event.extendedProps.holiday) {
                info.el.title = info.event.title;
                return;
            }

            const type = info.event.extendedProps.entry_type;

            if (type === 'time_credit') {
                info.el.classList.add('time-credit-event');
                return;
            }

            if (type === 'sick') {
                info.el.classList.add('sick-event');
                return;
            }

            if (type === 'doctor') {
                info.el.classList.add('doctor-event');
                return;
            }

            if (type === 'dentist') {
                info.el.classList.add('dentist-event');
            }

        }

    });

    calendar.render();

    // Knoppen.
    document.getElementById('addLeave').addEventListener(
        'click',
        () => openNew()
    );

    document.getElementById('closeDialog').addEventListener(
        'click',
        () => dialog.close()
    );

    document.getElementById('cancelDialog').addEventListener(
        'click',
        () => dialog.close()
    );

    // Boeking opslaan.
    form.addEventListener('submit', async (event) => {

        event.preventDefault();

        errorEl.textContent = '';

        const type = entryType.value;

        const isCredit = type === 'time_credit';
        const isSick = type === 'sick';
        const isAppointment = type === 'doctor' || type === 'dentist';

        const isNonLeave = isSick || isAppointment;

        const employee = document.getElementById('employeeId');

        const commonPayload = {
            csrf: window.APP_CONFIG.csrf,
            note: document.getElementById('note').value
        };

        if (employee) {
            commonPayload.user_id = Number(employee.value);
        }

        let payload;

        if (isCredit) {

            const minutes = Number(creditMinutesInput.value);

            if (!Number.isInteger(minutes) || minutes < 15) {

                errorEl.textContent =
                    'Tijd-voor-tijd moet minimaal 15 minuten zijn.';

                return;

            }

            if (!creditDateInput.value) {

                errorEl.textContent =
                    'Vul een datum in voor tijd-voor-tijd.';

                return;

            }

            payload = {

                ...commonPayload,

                action: 'save_credit',

                id: document.getElementById('leaveId').value,

                work_date: creditDateInput.value,

                minutes: minutes

            };

        } else {

            payload = {

                ...commonPayload,

                action: 'save',

                id: Number(
                    document.getElementById('leaveId').value || 0
                ),

                booking_type: type,

                start_date: startDateInput.value,

                end_date: endDateInput.value,

                start_time: startTimeInput.value,

                end_time: endTimeInput.value,

                calculation_mode: isNonLeave ? 'automatic' : mode.value,

                hours: isNonLeave
                    ? 0
                    : document.getElementById('hours').value

            };

        }

        try {

            await request('api.php', {

                method: 'POST',

                headers: {
                    'Content-Type': 'application/json'
                },

                body: JSON.stringify(payload)

            });

            dialog.close();

            window.location.reload();

        } catch (err) {

            errorEl.textContent = err.message;

        }

    });

    // Boeking verwijderen.
    deleteButton.addEventListener('click', async () => {

        if (!confirm('Weet je zeker dat je deze boeking wilt verwijderen?')) {
            return;
        }

        const id = document.getElementById('leaveId').value;

        const isCredit = entryType.value === 'time_credit';

        try {

            await request('api.php', {

                method: 'POST',

                headers: {
                    'Content-Type': 'application/json'
                },

                body: JSON.stringify({

                    action: isCredit ? 'delete_credit' : 'delete',

                    id: id,

                    csrf: window.APP_CONFIG.csrf

                })

            });

            dialog.close();

            window.location.reload();

        } catch (err) {

            errorEl.textContent = err.message;

        }

    });

});