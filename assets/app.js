document.addEventListener('DOMContentLoaded', () => {

    const calendarEl = document.getElementById('calendar');

    if (!calendarEl) return;

    const dialog = document.getElementById('leaveDialog');
    const form = document.getElementById('leaveForm');
    const mode = document.getElementById('calculationMode');
    const hoursWrap = document.getElementById('hoursWrap');
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

    let calendar;

    // Datum omzetten naar YYYY-MM-DD.
    const localDate = (date) => {

        const d = new Date(date);

        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    };

    // Weergave aanpassen aan het soort boeking.
    const setEntryTypeUI = () => {

        const isCredit = entryType.value === 'time_credit';

        leaveFields.classList.toggle('hidden', isCredit);
        creditFields.classList.toggle('hidden', !isCredit);

        startDateInput.required = !isCredit;
        endDateInput.required = !isCredit;
        startTimeInput.required = !isCredit;
        endTimeInput.required = !isCredit;

        creditDateInput.required = isCredit;
        creditMinutesInput.required = isCredit;

        if (isCredit) {

            document.getElementById('dialogTitle').textContent =
                'Tijd-voor-tijd toevoegen';

        } else {

            document.getElementById('dialogTitle').textContent =
                'Verlof inplannen';

        }

    };

    // Handmatige berekening tonen of verbergen.
    const setModeUI = () => {

        hoursWrap.classList.toggle(
            'hidden',
            mode.value !== 'manual'
        );

    };

    // Luisteren naar wijzigingen.
    entryType.addEventListener('change', setEntryTypeUI);
    mode.addEventListener('change', setModeUI);

    // API-verzoek versturen.
    async function request(url, options = {}) {

        const response = await fetch(url, options);

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.error || 'Er ging iets mis.');
        }

        return data;

    }

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

        startDateInput.value = event.startStr.slice(0, 10);

        endDateInput.value = event.end
            ? localDate(new Date(event.end.getTime() - 86400000))
            : event.startStr.slice(0, 10);

        document.getElementById('note').value = p.note || '';

        const employee = document.getElementById('employeeId');

        if (employee && p.user_id) {
            employee.value = p.user_id;
        }

        if (p.entry_type === 'time_credit') {

            entryType.value = 'time_credit';

            creditDateInput.value = event.startStr.slice(0, 10);

            creditMinutesInput.value = p.minutes || 15;

        } else {

            entryType.value = 'leave';

            startTimeInput.value = p.start_time
                ? p.start_time.substring(0, 5)
                : '08:45';

            endTimeInput.value = p.end_time
                ? p.end_time.substring(0, 5)
                : '16:30';

            mode.value = p.mode || 'automatic';

            document.getElementById('hours').value = p.hours || 0;

        }

        setModeUI();
        setEntryTypeUI();

        errorEl.textContent = '';

        document.getElementById('dialogTitle').textContent =
            p.entry_type === 'time_credit'
                ? 'Tijd-voor-tijd aanpassen'
                : 'Verlof aanpassen';

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

    if (info.event.extendedProps.entry_type === 'time_credit') {
        info.el.classList.add('time-credit-event');
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

        const isCredit = entryType.value === 'time_credit';

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

                start_date: startDateInput.value,

                end_date: endDateInput.value,

                start_time: startTimeInput.value,

                end_time: endTimeInput.value,

                calculation_mode: mode.value,

                hours: document.getElementById('hours').value

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