document.addEventListener('DOMContentLoaded', () => {

    const calendarEl = document.getElementById('calendar');

    if (!calendarEl) return;

    const dialog = document.getElementById('leaveDialog');
    const form = document.getElementById('leaveForm');
    const mode = document.getElementById('calculationMode');
    const hoursWrap = document.getElementById('hoursWrap');
    const errorEl = document.getElementById('formError');
    const deleteButton = document.getElementById('deleteLeave');

    const startDateInput = document.getElementById('startDate');
    const endDateInput = document.getElementById('endDate');
    const startTimeInput = document.getElementById('startTime');
    const endTimeInput = document.getElementById('endTime');

    let calendar;

    // Lokale datum formatteren.
    const localDate = (date) => {

        const d = new Date(date);

        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    };

    // Automatisch of handmatig berekenen.
    const setModeUI = () => {

        hoursWrap.classList.toggle(
            'hidden',
            mode.value !== 'manual'
        );

    };

    mode.addEventListener('change', setModeUI);

    // Verzoek naar de API versturen.
    async function request(url, options = {}) {

        const response = await fetch(url, options);

        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
            throw new Error(data.error || 'Er ging iets mis.');
        }

        return data;

    }

    // Nieuw verlof openen.
    function openNew(start, end) {

        form.reset();

        document.getElementById('leaveId').value = '';

        document.getElementById('dialogTitle').textContent =
            'Verlof inplannen';

        startDateInput.value =
            start || localDate(new Date());

        endDateInput.value =
            end || start || localDate(new Date());

        // Standaard werktijden.
        startTimeInput.value = '08:45';
        endTimeInput.value = '16:30';

        // Medewerker instellen.
        if (!window.APP_CONFIG.isAdmin) {

            const employee = document.getElementById('employeeId');

            if (employee) {
                employee.value = window.APP_CONFIG.currentUserId;
            }

        }

        mode.value = 'automatic';

        setModeUI();

        errorEl.textContent = '';

        deleteButton.classList.add('hidden');

        dialog.showModal();

    }

    // Bestaand verlof aanpassen.
    function openEdit(event) {

        const p = event.extendedProps || {};

        if (p.holiday) return;

        form.reset();

        document.getElementById('leaveId').value = event.id;

        document.getElementById('dialogTitle').textContent =
            'Verlof aanpassen';

        startDateInput.value = event.startStr;

        endDateInput.value = event.end
            ? localDate(new Date(event.end.getTime() - 86400000))
            : event.startStr;

        // Opgeslagen tijden ophalen.
        // Oude boekingen zonder tijden krijgen de normale werktijden.
        startTimeInput.value = p.start_time
            ? p.start_time.substring(0, 5)
            : '08:45';

        endTimeInput.value = p.end_time
            ? p.end_time.substring(0, 5)
            : '16:30';

        document.getElementById('note').value = p.note || '';

        mode.value = p.mode || 'automatic';

        document.getElementById('hours').value = p.hours || 0;

        const employee = document.getElementById('employeeId');

        if (employee && p.user_id) {
            employee.value = p.user_id;
        }

        setModeUI();

        errorEl.textContent = '';

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

        // Meerdere dagen selecteren.
        select: (info) => {

            const end = new Date(info.end);

            end.setDate(end.getDate() - 1);

            openNew(
                info.startStr.slice(0, 10),
                localDate(end)
            );

            calendar.unselect();

        },

        // Eén dag selecteren.
        dateClick: (info) => {

            openNew(info.dateStr, info.dateStr);

        },

        // Verlofboeking openen.
        eventClick: (info) => {

            if (info.event.extendedProps.holiday) return;

            openEdit(info.event);

        },

        // Feestdagen een tooltip geven.
        eventDidMount: (info) => {

            if (info.event.extendedProps.holiday) {
                info.el.title = info.event.title;
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

    // Verlof opslaan.
    form.addEventListener('submit', async (event) => {

        event.preventDefault();

        errorEl.textContent = '';

        const payload = {

            action: 'save',

            csrf: window.APP_CONFIG.csrf,

            id: Number(
                document.getElementById('leaveId').value || 0
            ),

            start_date: startDateInput.value,

            end_date: endDateInput.value,

            start_time: startTimeInput.value,

            end_time: endTimeInput.value,

            calculation_mode: mode.value,

            hours: document.getElementById('hours').value,

            note: document.getElementById('note').value

        };

        // Medewerker meesturen voor beheerders.
        const employee = document.getElementById('employeeId');

        if (employee) {
            payload.user_id = Number(employee.value);
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

            calendar.refetchEvents();

            window.location.reload();

        } catch (err) {

            errorEl.textContent = err.message;

        }

    });

    // Verlof verwijderen.
    deleteButton.addEventListener('click', async () => {

        if (
            !confirm(
                'Weet je zeker dat je deze verlofboeking wilt verwijderen?'
            )
        ) {
            return;
        }

        try {

            await request('api.php', {

                method: 'POST',

                headers: {
                    'Content-Type': 'application/json'
                },

                body: JSON.stringify({

                    action: 'delete',

                    id: Number(
                        document.getElementById('leaveId').value
                    ),

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