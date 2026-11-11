
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

    // Elementen voor uitbetalingen.
    const payoutDialog = document.getElementById('payoutDialog');
    const payoutForm = document.getElementById('payoutForm');
    const payoutHistory = document.getElementById('payoutHistory');
    const payoutError = document.getElementById('payoutError');
    const payoutDateInput = document.getElementById('payoutDate');
    const payoutHoursInput = document.getElementById('payoutHours');
    const payoutNoteInput = document.getElementById('payoutNote');
    const payoutEmployeeInput = document.getElementById('payoutEmployeeId');

    // Elementen voor meldingen.
    const notificationButton =
        document.getElementById('notificationButton');

    const notificationBadge =
        document.getElementById('notificationBadge');

    const notificationDialog =
        document.getElementById('notificationDialog');

    const notificationList =
        document.getElementById('notificationList');

    const closeNotificationDialog =
        document.getElementById('closeNotificationDialog');

    const ANNUAL_ALLOWANCE = 193.75;

    let calendar;

    let currentBalanceYear = new Date().getFullYear();

    // Houdt bij of we een aanvraag aan het aanpassen zijn.
    let editingRequestId = null;

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

    /*
    |--------------------------------------------------------------------------
    | Eigen bevestigingsvenster
    |--------------------------------------------------------------------------
    */

    function showConfirmDialog(message) {

        return new Promise((resolve) => {

            const confirmDialog = document.createElement('dialog');

            confirmDialog.className = 'confirm-dialog';

            const heading = document.createElement('div');
            heading.className = 'modal-heading';

            const title = document.createElement('h2');
            title.textContent = 'Weet je het zeker?';

            heading.appendChild(title);

            const text = document.createElement('p');
            text.className = 'confirm-message';
            text.textContent = message;

            const actions = document.createElement('div');
            actions.className = 'modal-actions';

            const spacer = document.createElement('span');
            spacer.className = 'spacer';

            const cancelButton = document.createElement('button');
            cancelButton.type = 'button';
            cancelButton.className = 'button secondary';
            cancelButton.textContent = 'Annuleren';

            const confirmButton = document.createElement('button');
            confirmButton.type = 'button';
            confirmButton.className = 'button confirm-delete-button';
            confirmButton.textContent = 'Aanvraag verwijderen';

            actions.append(
                spacer,
                cancelButton,
                confirmButton
            );

            confirmDialog.append(
                heading,
                text,
                actions
            );

            document.body.appendChild(confirmDialog);

            let finished = false;

            const finish = (result) => {

                if (finished) return;

                finished = true;

                confirmDialog.close();
                confirmDialog.remove();

                resolve(result);

            };

            cancelButton.addEventListener('click', () => {
                finish(false);
            });

            confirmButton.addEventListener('click', () => {
                finish(true);
            });

            confirmDialog.addEventListener('cancel', (event) => {
                event.preventDefault();
                finish(false);
            });

            confirmDialog.showModal();

        });

    }

    /*
    |--------------------------------------------------------------------------
    | Meldingen
    |--------------------------------------------------------------------------
    */

    function updateNotificationBadge(unreadCount) {

        if (!notificationBadge) return;

        notificationBadge.classList.toggle(
            'hidden',
            Number(unreadCount) <= 0
        );

    }

    function formatNotificationDate(dateValue) {

        if (!dateValue) return '';

        const date = new Date(
            dateValue.replace(' ', 'T')
        );

        if (Number.isNaN(date.getTime())) {
            return dateValue;
        }

        return date.toLocaleString('nl-NL', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });

    }

    function renderNotifications(notifications) {

        if (!notificationList) return;

        notificationList.replaceChildren();

        if (!notifications || notifications.length === 0) {

            const emptyMessage = document.createElement('p');

            emptyMessage.className = 'muted';
            emptyMessage.textContent = 'Je hebt momenteel geen meldingen.';

            notificationList.appendChild(emptyMessage);

            return;

        }

        notifications.forEach((notification) => {

            const item = document.createElement('div');

            item.className = 'notification-item';

            if (!notification.read_at) {
                item.classList.add('unread');
            }

            const title = document.createElement('h3');

            title.textContent = notification.title;

            const message = document.createElement('p');

            message.textContent = notification.message;

            const date = document.createElement('small');

            date.textContent = formatNotificationDate(
                notification.created_at
            );

            item.append(
                title,
                message,
                date
            );

            notificationList.appendChild(item);

        });

    }

    async function loadNotifications() {

        if (!notificationButton || !notificationBadge) return;

        try {

            const data = await request('api.php?notifications=1');

            const notifications = data.notifications || [];

            renderNotifications(notifications);

            updateNotificationBadge(data.unread_count || 0);

        } catch (err) {

            console.error('Meldingen ophalen mislukt:', err);

        }

    }

    async function markNotificationsRead() {

        try {

            await request('api.php', {

                method: 'POST',

                headers: {
                    'Content-Type': 'application/json'
                },

                body: JSON.stringify({

                    action: 'mark_notifications_read',

                    csrf: window.APP_CONFIG.csrf

                })

            });

            updateNotificationBadge(0);

            await loadNotifications();

        } catch (err) {

            console.error('Meldingen markeren als gelezen mislukt:', err);

        }

    }

    async function openNotificationDialog() {

        if (!notificationDialog) return;

        notificationDialog.showModal();

        if (notificationList) {
            notificationList.textContent = 'Meldingen worden geladen...';
        }

        await loadNotifications();

        await markNotificationsRead();

    }

    if (notificationButton && notificationDialog) {

        notificationButton.addEventListener(
            'click',
            openNotificationDialog
        );

    }

    if (closeNotificationDialog && notificationDialog) {

        closeNotificationDialog.addEventListener(
            'click',
            () => notificationDialog.close()
        );

    }

    loadNotifications();

    /*
    |--------------------------------------------------------------------------
    | Saldo
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Uitbetalingen
    |--------------------------------------------------------------------------
    */

    async function loadPayoutHistory(year = currentBalanceYear) {

        if (!payoutHistory) return;

        payoutHistory.textContent = 'Uitbetalingen worden geladen...';

        try {

            let url =
                `api.php?payouts_year=${encodeURIComponent(year)}`;

            if (payoutEmployeeInput && payoutEmployeeInput.value) {

                url +=
                    `&user_id=${encodeURIComponent(payoutEmployeeInput.value)}`;

            }

            const data = await request(url);

            const payouts = Array.isArray(data)
                ? data
                : (data.payouts || data.entries || []);

            payoutHistory.replaceChildren();

            if (payouts.length === 0) {

                const emptyMessage = document.createElement('p');

                emptyMessage.className = 'muted';
                emptyMessage.textContent =
                    `Er zijn nog geen uitbetalingen geregistreerd voor ${year}.`;

                payoutHistory.appendChild(emptyMessage);

                return;

            }

            const table = document.createElement('table');
            table.className = 'payout-table';

            const thead = document.createElement('thead');
            const headerRow = document.createElement('tr');

            const headers = [
                'Datum',
                'Medewerker',
                'Aantal uren',
                'Opmerking',
                'Actie'
            ];

            headers.forEach((header) => {

                const th = document.createElement('th');

                th.textContent = header;

                headerRow.appendChild(th);

            });

            thead.appendChild(headerRow);
            table.appendChild(thead);

            const tbody = document.createElement('tbody');

            payouts.forEach((payout) => {

                const row = document.createElement('tr');

                const dateCell = document.createElement('td');
                const employeeCell = document.createElement('td');
                const hoursCell = document.createElement('td');
                const noteCell = document.createElement('td');
                const actionCell = document.createElement('td');

                const date = new Date(
                    `${payout.payout_date}T00:00:00`
                );

                dateCell.textContent = Number.isNaN(date.getTime())
                    ? payout.payout_date
                    : date.toLocaleDateString('nl-NL');

                employeeCell.textContent =
                    payout.name || 'Medewerker';

                hoursCell.textContent =
                    `${formatHours(payout.hours)} uur`;

                noteCell.textContent =
                    payout.note || '—';

                const deletePayoutButton =
                    document.createElement('button');

                deletePayoutButton.type = 'button';
                deletePayoutButton.className =
                    'button secondary payout-delete-button';

                deletePayoutButton.textContent = 'Verwijderen';

                deletePayoutButton.addEventListener('click', () => {

                    deletePayout(payout.id);

                });

                actionCell.appendChild(deletePayoutButton);

                row.append(
                    dateCell,
                    employeeCell,
                    hoursCell,
                    noteCell,
                    actionCell
                );

                tbody.appendChild(row);

            });

            table.appendChild(tbody);
            payoutHistory.appendChild(table);

        } catch (err) {

            console.error('Uitbetalingen ophalen mislukt:', err);

            payoutHistory.textContent =
                `Uitbetalingen konden niet worden opgehaald: ${err.message}`;

        }

    }

    async function deletePayout(id) {

        if (!confirm('Weet je zeker dat je deze uitbetaling wilt verwijderen?')) {
            return;
        }

        try {

            await request('api.php', {

                method: 'POST',

                headers: {
                    'Content-Type': 'application/json'
                },

                body: JSON.stringify({

                    action: 'delete_payout',

                    id: id,

                    csrf: window.APP_CONFIG.csrf

                })

            });

            await updateBalance(currentBalanceYear);

            await loadPayoutHistory(currentBalanceYear);

        } catch (err) {

            alert(err.message);

        }

    }

    function openPayoutDialog() {

        if (!payoutDialog || !payoutForm) return;

        payoutForm.reset();

        payoutDateInput.value = localDate(new Date());

        if (payoutEmployeeInput && !window.APP_CONFIG.isAdmin) {
            payoutEmployeeInput.value = window.APP_CONFIG.currentUserId;
        }

        payoutError.textContent = '';

        payoutDialog.showModal();

    }

    if (payoutForm) {

        payoutForm.addEventListener('submit', async (event) => {

            event.preventDefault();

            payoutError.textContent = '';

            const hours = Number(payoutHoursInput.value);

            if (!payoutDateInput.value) {

                payoutError.textContent =
                    'Vul een datum in voor de uitbetaling.';

                return;

            }

            if (!Number.isFinite(hours) || hours <= 0 || hours > 1000) {

                payoutError.textContent =
                    'Vul een geldig aantal uren in.';

                return;

            }

            const payload = {

                action: 'save_payout',

                csrf: window.APP_CONFIG.csrf,

                payout_date: payoutDateInput.value,

                hours: hours,

                note: payoutNoteInput.value

            };

            if (payoutEmployeeInput) {
                payload.user_id = Number(payoutEmployeeInput.value);
            }

            try {

                await request('api.php', {

                    method: 'POST',

                    headers: {
                        'Content-Type': 'application/json'
                    },

                    body: JSON.stringify(payload)

                });

                payoutDialog.close();

                const payoutYear =
                    Number(payoutDateInput.value.slice(0, 4));

                await updateBalance(payoutYear);

                await loadPayoutHistory(currentBalanceYear);

                if (payoutYear !== currentBalanceYear) {

                    await loadPayoutHistory(payoutYear);

                }

            } catch (err) {

                payoutError.textContent = err.message;

            }

        });

    }

    /*
    |--------------------------------------------------------------------------
    | Boekingen
    |--------------------------------------------------------------------------
    */

    const setEntryTypeUI = () => {

        const type = entryType.value;

        const isCredit = type === 'time_credit';
        const isSick = type === 'sick';

        const isAppointment = [
            'doctor',
            'dentist',
            'specialist'
        ].includes(type);

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
            leave: 'Verlof aanvragen',
            sick: 'Ziekte registreren',
            time_credit: 'Tijd-voor-tijd toevoegen',
            doctor: 'Doktersafspraak aanvragen',
            dentist: 'Tandartsafspraak aanvragen',
            specialist: 'Specialistafspraak aanvragen'
        };

        document.getElementById('dialogTitle').textContent =
            titles[type] || 'Boeking toevoegen';

    };

    const setModeUI = () => {

        const isNonLeave = [
            'sick',
            'doctor',
            'dentist',
            'specialist'
        ].includes(entryType.value);

        hoursWrap.classList.toggle(
            'hidden',
            isNonLeave || mode.value !== 'manual'
        );

    };

    entryType.addEventListener('change', setEntryTypeUI);
    mode.addEventListener('change', setModeUI);

    // Nieuwe boeking openen.
    function openNew(start, end) {

        editingRequestId = null;

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

        deleteButton.textContent = 'Boeking verwijderen';
        deleteButton.classList.add('hidden');

        dialog.showModal();

    }

    // Bestaande boeking of aanvraag aanpassen.
    function openEdit(event) {

        const p = event.extendedProps || {};

        if (p.holiday) return;

        const isPendingRequest =
            p.pending_request || p.request_status === 'pending';

        if (isPendingRequest) {

            if (window.APP_CONFIG.isAdmin) {
                return;
            }

            editingRequestId = p.request_id;

        } else {

            editingRequestId = null;

        }

        form.reset();

        document.getElementById('leaveId').value =
            isPendingRequest ? '' : event.id;

        if (p.entry_type === 'time_credit') {

            entryType.value = 'time_credit';

            creditDateInput.value = event.startStr.slice(0, 10);

            creditMinutesInput.value = p.minutes || 15;

        } else {

            entryType.value = [
                'leave',
                'sick',
                'doctor',
                'dentist',
                'specialist'
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
            leave: isPendingRequest ? 'Verlofaanvraag' : 'Verlof aanpassen',
            sick: 'Ziekte aanpassen',
            time_credit: 'Tijd-voor-tijd aanpassen',
            doctor: 'Doktersafspraak',
            dentist: 'Tandartsafspraak',
            specialist: 'Specialistafspraak'
        };

        document.getElementById('dialogTitle').textContent =
            titles[p.entry_type] || 'Boeking aanpassen';

        // Een aanvraag in afwachting krijgt een eigen verwijderknop.
        if (isPendingRequest) {

            deleteButton.textContent = 'Aanvraag verwijderen';
            deleteButton.classList.remove('hidden');

        } else {

            deleteButton.textContent = 'Boeking verwijderen';
            deleteButton.classList.remove('hidden');

        }

        dialog.showModal();

    }

    /*
    |--------------------------------------------------------------------------
    | Kalender aanmaken
    |--------------------------------------------------------------------------
    */

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

            currentBalanceYear = selectedYear;

            updateBalance(selectedYear);

            loadPayoutHistory(selectedYear);

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

        // Aanvragen en boekingen aanklikken.
        eventClick: (info) => {

            const p = info.event.extendedProps || {};

            if (p.holiday) return;

            if (
                (p.pending_request || p.request_status === 'pending') &&
                window.APP_CONFIG.isAdmin
            ) {

                alert('Deze aanvraag wacht nog op goedkeuring.');

                return;

            }

            // Medewerker kan de eigen aanvraag openen.
            openEdit(info.event);

        },

        eventDidMount: (info) => {

            if (info.event.extendedProps.holiday) {
                info.el.title = info.event.title;
                return;
            }

            const p = info.event.extendedProps || {};

            const type = p.entry_type;

            if (p.pending_request) {

                info.el.classList.add('pending-request-event');

                info.el.title =
                    'Deze aanvraag wacht op goedkeuring. Klik om te bekijken en eventueel te verwijderen.';

            }

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
                return;
            }

            if (type === 'specialist') {
                info.el.classList.add('specialist-event');
            }

        }

    });

    calendar.render();

    /*
    |--------------------------------------------------------------------------
    | Knoppen voor boekingen
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Knoppen voor uitbetalingen
    |--------------------------------------------------------------------------
    */

    const addPayoutButton = document.getElementById('addPayout');

    if (addPayoutButton && payoutDialog) {

        addPayoutButton.addEventListener(
            'click',
            openPayoutDialog
        );

    }

    const closePayoutButton =
        document.getElementById('closePayoutDialog');

    const cancelPayoutButton =
        document.getElementById('cancelPayoutDialog');

    if (closePayoutButton && payoutDialog) {

        closePayoutButton.addEventListener(
            'click',
            () => payoutDialog.close()
        );

    }

    if (cancelPayoutButton && payoutDialog) {

        cancelPayoutButton.addEventListener(
            'click',
            () => payoutDialog.close()
        );

    }

    if (payoutEmployeeInput) {

        payoutEmployeeInput.addEventListener('change', () => {

            loadPayoutHistory(currentBalanceYear);

        });

    }

    /*
    |--------------------------------------------------------------------------
    | Boeking opslaan
    |--------------------------------------------------------------------------
    */

    form.addEventListener('submit', async (event) => {

        event.preventDefault();

        errorEl.textContent = '';

        const type = entryType.value;

        const isCredit = type === 'time_credit';
        const isSick = type === 'sick';

        const isAppointment = [
            'doctor',
            'dentist',
            'specialist'
        ].includes(type);

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

                action: editingRequestId
                    ? 'update_request'
                    : 'save',

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

            if (editingRequestId) {
                payload.request_id = editingRequestId;
            }

        }

        try {

            const data = await request('api.php', {

                method: 'POST',

                headers: {
                    'Content-Type': 'application/json'
                },

                body: JSON.stringify(payload)

            });

            dialog.close();

            editingRequestId = null;

            if (data.pending) {

                calendar.refetchEvents();

                await loadNotifications();

                await updateBalance(currentBalanceYear);

                return;

            }

            calendar.refetchEvents();

            await updateBalance(currentBalanceYear);

            await loadNotifications();

        } catch (err) {

            errorEl.textContent = err.message;

        }

    });

    /*
    |--------------------------------------------------------------------------
    | Boeking of aanvraag verwijderen
    |--------------------------------------------------------------------------
    */

    deleteButton.addEventListener('click', async () => {

        const isPendingRequest = Boolean(editingRequestId);

        // Voor een aanvraag gebruiken we onze eigen pop-up.
        if (isPendingRequest) {

            const confirmed = await showConfirmDialog(
                'Weet je zeker dat je deze aanvraag wilt verwijderen? Deze actie kan niet ongedaan worden gemaakt.'
            );

            if (!confirmed) {
                return;
            }

        } else {

            // Gewone boekingen behouden hun bestaande bevestiging.
            if (!confirm('Weet je zeker dat je deze boeking wilt verwijderen?')) {
                return;
            }

        }

        const id = document.getElementById('leaveId').value;

        const isCredit = entryType.value === 'time_credit';

        try {

            // Een aanvraag in afwachting verwijderen.
            if (isPendingRequest) {

                await request('api.php', {

                    method: 'POST',

                    headers: {
                        'Content-Type': 'application/json'
                    },

                    body: JSON.stringify({

                        action: 'delete_request',

                        request_id: editingRequestId,

                        csrf: window.APP_CONFIG.csrf

                    })

                });

            } else {

                // Een gewone boeking of tijd-voor-tijd verwijderen.
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

            }

            dialog.close();

            editingRequestId = null;

            calendar.refetchEvents();

            await updateBalance(currentBalanceYear);

            await loadNotifications();

        } catch (err) {

            errorEl.textContent = err.message;

        }

    });

});
