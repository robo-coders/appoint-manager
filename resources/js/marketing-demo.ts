type StaffMember = {
    initial: string;
    name: string;
    role: string;
    hours: string;
    booked: string;
    on: number[];
};

type Vertical = {
    label: string;
    biz: string;
    bookFor: string;
    lineTitle: string;
    withStaff: string;
    service: string;
    dur: string;
    price: string;
    dep: string;
    bal: string;
    svc2: string;
    dur2: string;
    price2: string;
    dep2: string;
    svc3: string;
    dur3: string;
    cancelName: string;
    cancelFirst: string;
    cancelLate: string;
    waitName: string;
    waitFirst: string;
    waitWants: string;
    q2: string;
    q2w: string;
    q3: string;
    q3w: string;
    d11a: string;
    d11b: string;
    d10: string;
    d13: string;
    dayTotal: string;
    dayDeposits: string;
    staff: StaffMember[];
    loyalSubj: string;
    loyalUnit: string;
    loyalReward: string;
};

type Act = {
    label: string;
    dur: number;
    steps: number[];
    context: string;
};

const ACTS: Act[] = [
    { label: 'Booking portal', dur: 4000, steps: [0, 600, 1300, 1900, 2600, 3100, 3500], context: 'Customer booking portal' },
    { label: 'Waitlist fills', dur: 7000, steps: [0, 500, 1300, 2100, 4200, 5000, 5900], context: 'Waitlist automation' },
    { label: 'Diary', dur: 4000, steps: [0], context: 'Diary — day view' },
    { label: 'No-show', dur: 4500, steps: [0, 600, 1100, 1900, 2400, 2600, 3300], context: 'Diary — day view' },
    { label: 'Staff', dur: 3500, steps: [0], context: 'Staff & hours' },
    { label: 'Loyalty', dur: 4000, steps: [0, 900], context: 'Loyalty cards' },
];

const VERTICALS: Vertical[] = [
    {
        label: 'Dog Groomers',
        biz: 'Bramble & Bone',
        bookFor: 'Bella',
        lineTitle: 'Full groom — Bella (Cockapoo)',
        withStaff: 'with Amy',
        service: 'Full groom',
        dur: '60 min',
        price: '£45.00',
        dep: '£15.00',
        bal: '£30.00',
        svc2: 'Bath and blow dry',
        dur2: '45 min',
        price2: '£28.00',
        dep2: '£10.00',
        svc3: 'Nail clip',
        dur3: '15 min',
        cancelName: 'Coco Marsh — Pip',
        cancelFirst: 'Coco',
        cancelLate: 'Coco is 12 minutes late',
        waitName: 'Max Ellery — Rufus',
        waitFirst: 'Max Ellery',
        waitWants: 'Full groom — Rufus',
        q2: 'Sadie Warrick',
        q2w: 'Bath and blow dry — Juno',
        q3: 'Otis Lund',
        q3w: 'Nail clip — Sprout',
        d11a: 'Bella',
        d11b: 'Rufus',
        d10: 'Elsie Pike',
        d13: 'Wilf Hardy',
        dayTotal: '£191.00',
        dayDeposits: '£55.00',
        staff: [
            { initial: 'A', name: 'Amy Rowntree', role: 'Groomer · owner', hours: 'Mon–Fri · 09:00–17:00 · 38 h', booked: '82%', on: [1, 1, 1, 1, 1, 0, 0] },
            { initial: 'J', name: 'Jordan Vale', role: 'Groomer', hours: 'Tue–Sat · 10:00–18:00 · 36 h', booked: '74%', on: [0, 1, 1, 1, 1, 1, 0] },
            { initial: 'N', name: 'Nia Bexley', role: 'Bather · part time', hours: 'Thu–Sat · 09:00–15:00 · 32 h', booked: '61%', on: [0, 0, 0, 1, 1, 1, 0] },
        ],
        loyalSubj: 'Bella (Cockapoo)',
        loyalUnit: 'grooms',
        loyalReward: '£10 off',
    },
    {
        label: 'Barbers',
        biz: 'Fettle & Co',
        bookFor: 'your cut',
        lineTitle: 'Cut and beard — Theo Brand',
        withStaff: 'with Ryan',
        service: 'Cut and beard',
        dur: '45 min',
        price: '£32.00',
        dep: '£10.00',
        bal: '£22.00',
        svc2: 'Fade',
        dur2: '30 min',
        price2: '£18.00',
        dep2: '£5.00',
        svc3: 'Beard trim',
        dur3: '15 min',
        cancelName: 'Sam Reddick',
        cancelFirst: 'Sam',
        cancelLate: 'Sam is 12 minutes late',
        waitName: 'Max Ellery',
        waitFirst: 'Max Ellery',
        waitWants: 'Cut and beard',
        q2: 'Joel Tarrant',
        q2w: 'Fade',
        q3: 'Otis Lund',
        q3w: 'Beard trim',
        d11a: 'Theo Brand',
        d11b: 'Max Ellery',
        d10: 'Elsie Pike',
        d13: 'Wilf Hardy',
        dayTotal: '£146.00',
        dayDeposits: '£35.00',
        staff: [
            { initial: 'R', name: 'Ryan Okoye', role: 'Barber · owner', hours: 'Tue–Sat · 09:00–18:00 · 42 h', booked: '88%', on: [0, 1, 1, 1, 1, 1, 0] },
            { initial: 'D', name: 'Dev Mahal', role: 'Barber', hours: 'Mon–Fri · 10:00–19:00 · 40 h', booked: '79%', on: [1, 1, 1, 1, 1, 0, 0] },
            { initial: 'K', name: 'Kit Farrow', role: 'Barber · part time', hours: 'Thu–Sat · 12:00–19:00 · 24 h', booked: '66%', on: [0, 0, 0, 1, 1, 1, 0] },
        ],
        loyalSubj: 'Theo Brand',
        loyalUnit: 'cuts',
        loyalReward: 'a free cut',
    },
    {
        label: 'Dentists',
        biz: 'Kingsway Dental',
        bookFor: 'your appointment',
        lineTitle: 'Hygienist clean — Naomi Clarke',
        withStaff: 'with Dr Olawale',
        service: 'Hygienist clean',
        dur: '40 min',
        price: '£68.00',
        dep: '£20.00',
        bal: '£48.00',
        svc2: 'Check-up',
        dur2: '20 min',
        price2: '£45.00',
        dep2: '£15.00',
        svc3: 'X-ray review',
        dur3: '15 min',
        cancelName: 'Marta Hanley',
        cancelFirst: 'Marta',
        cancelLate: 'Marta is 12 minutes late',
        waitName: 'Max Ellery',
        waitFirst: 'Max Ellery',
        waitWants: 'Hygienist clean',
        q2: 'Sadie Warrick',
        q2w: 'Check-up',
        q3: 'Otis Lund',
        q3w: 'X-ray review',
        d11a: 'Naomi Clarke',
        d11b: 'Max Ellery',
        d10: 'Elsie Pike',
        d13: 'Wilf Hardy',
        dayTotal: '£294.00',
        dayDeposits: '£75.00',
        staff: [
            { initial: 'R', name: 'Dr Ruth Olawale', role: 'Dentist · principal', hours: 'Mon–Thu · 08:30–17:00 · 34 h', booked: '91%', on: [1, 1, 1, 1, 0, 0, 0] },
            { initial: 'I', name: 'Dr Ian Petrie', role: 'Dentist', hours: 'Tue–Fri · 09:00–17:30 · 34 h', booked: '84%', on: [0, 1, 1, 1, 1, 0, 0] },
            { initial: 'M', name: 'Mara Quill', role: 'Hygienist', hours: 'Wed–Sat · 09:00–16:00 · 28 h', booked: '77%', on: [0, 0, 1, 1, 1, 1, 0] },
        ],
        loyalSubj: 'Naomi Clarke',
        loyalUnit: 'check-ups',
        loyalReward: '£15 off',
    },
    {
        label: 'Tattoo Studios',
        biz: 'Fen Street Tattoo',
        bookFor: 'your session',
        lineTitle: 'Half sleeve session — Iris Fenwick',
        withStaff: 'with Lena',
        service: 'Half sleeve',
        dur: '90 min',
        price: '£240.00',
        dep: '£60.00',
        bal: '£180.00',
        svc2: 'Fine-line piece',
        dur2: '45 min',
        price2: '£95.00',
        dep2: '£25.00',
        svc3: 'Touch-up',
        dur3: '30 min',
        cancelName: 'Cass Mowbray',
        cancelFirst: 'Cass',
        cancelLate: 'Cass is 12 minutes late',
        waitName: 'Max Ellery',
        waitFirst: 'Max Ellery',
        waitWants: 'Half sleeve session',
        q2: 'Sadie Warrick',
        q2w: 'Fine-line piece',
        q3: 'Otis Lund',
        q3w: 'Touch-up',
        d11a: 'Iris Fenwick',
        d11b: 'Max Ellery',
        d10: 'Elsie Pike',
        d13: 'Wilf Hardy',
        dayTotal: '£670.00',
        dayDeposits: '£145.00',
        staff: [
            { initial: 'L', name: 'Lena Voss', role: 'Artist · owner', hours: 'Wed–Sun · 11:00–19:00 · 40 h', booked: '94%', on: [0, 0, 1, 1, 1, 1, 1] },
            { initial: 'C', name: 'Cory Adeyemi', role: 'Artist', hours: 'Tue–Sat · 12:00–20:00 · 38 h', booked: '86%', on: [0, 1, 1, 1, 1, 1, 0] },
            { initial: 'B', name: 'Bea Hollins', role: 'Apprentice', hours: 'Thu–Sat · 12:00–18:00 · 28 h', booked: '58%', on: [0, 0, 0, 1, 1, 1, 0] },
        ],
        loyalSubj: 'Iris Fenwick',
        loyalUnit: 'sessions',
        loyalReward: '£40 off',
    },
];

const STAMP_DATES = ['12 Mar', '09 May', '04 Jul', '01 Aug', '22 Aug', '11 Sep', '', ''];
const WEEK_LETTERS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
const TOTAL = ACTS.reduce((n, a) => n + a.dur, 0);
const STARTS = ACTS.map((_, i) => ACTS.slice(0, i).reduce((n, a) => n + a.dur, 0));

const esc = (value: string): string =>
    value.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c] as string);

const firstName = (name: string): string => (name.startsWith('Dr') ? name.split(' ')[1] : name.split(' ')[0]);

const coverHours = (staff: StaffMember[]): number =>
    staff.reduce((n, p) => n + (parseInt((p.hours.match(/(\d+)\s*h$/) || ['', '0'])[1], 10) || 0), 0);

function renderBookingPortal(v: Vertical): string {
    const staff1 = firstName(v.staff[0].name);
    const days = [
        ['Tue', '09'],
        ['Wed', '10'],
        ['Thu', '11'],
        ['Fri', '12'],
    ]
        .map(
            ([dow, num]) =>
                `<div class="dm-day${num === '11' ? ' is-on' : ''}"><div class="dm-day-dow">${esc(dow)}</div><div class="dm-day-num">${esc(num)}</div></div>`,
        )
        .join('');

    const slots = ['9:00', '10:00', '11:00']
        .map(
            (time, i) =>
                `<div class="dm-slot" data-slot="${i}"${i === 2 ? ' data-cursor="slot11"' : ''}>` +
                `<div class="dm-slot-time">${esc(time)}</div>` +
                `<div class="dm-slot-sub">${esc(staff1)}</div>` +
                (i === 2
                    ? '<svg class="dm-trace" width="148" height="62" viewBox="0 0 148 62" fill="none" aria-hidden="true"><rect x="0.75" y="0.75" width="146.5" height="60.5" rx="6" stroke-width="1.5" stroke-dasharray="420"></rect></svg>'
                    : '') +
                '</div>',
        )
        .join('');

    return (
        '<div class="dm-act dm-act-portal">' +
        '<div class="dm-portal-col">' +
        '<div class="dm-eyebrow">Step 2 of 3</div>' +
        `<h3 class="dm-h3">Pick a time for ${esc(v.bookFor)}</h3>` +
        '<div class="dm-line">' +
        `<div class="dm-line-main"><div class="dm-strong">${esc(v.lineTitle)}</div>` +
        `<div class="dm-mono dm-quiet">${esc(v.biz)} · ${esc(v.withStaff)}</div></div>` +
        `<div class="dm-line-fig"><div class="dm-mono dm-fig">${esc(v.price)}</div>` +
        `<div class="dm-mono dm-quiet dm-fig-sub">${esc(v.dur)}</div></div>` +
        '</div>' +
        `<div class="dm-days">${days}</div>` +
        `<div class="dm-slots">${slots}</div>` +
        '<div class="dm-deposit" data-deposit>' +
        '<div><div class="dm-strong">Deposit due today</div>' +
        `<div class="dm-mono dm-quiet">Balance of ${esc(v.bal)} on the day</div></div>` +
        `<div class="dm-mono dm-deposit-fig">${esc(v.dep)}</div>` +
        '</div>' +
        '<div class="dm-confirm-row">' +
        '<div class="dm-confirm" data-cursor="confirm" data-confirm>Confirm and pay deposit</div>' +
        '<div class="dm-mono dm-quiet dm-confirmed" data-confirmed>Confirmed · Thu 11 Sep, 11:00</div>' +
        '</div>' +
        '<div class="dm-notes">' +
        '<div class="dm-mono dm-note">Free cancellation up to 24 h before the appointment.</div>' +
        '<div class="dm-mono dm-note">Deposit held by DiaryDesk and taken off the final bill.</div>' +
        '</div>' +
        '</div></div>'
    );
}

function syncBookingPortal(root: HTMLElement, step: number, v: Vertical): void {
    const traced = step >= 2;
    root.querySelectorAll<HTMLElement>('.dm-slot').forEach((el, i) => {
        el.classList.toggle('is-traced', traced && i === 2);
    });

    const deposit = root.querySelector<HTMLElement>('[data-deposit]');
    if (deposit) deposit.classList.toggle('is-in', step >= 3);

    const confirm = root.querySelector<HTMLElement>('[data-confirm]');
    if (confirm) {
        confirm.textContent = step >= 6 ? 'Booked' : 'Confirm and pay deposit';
        confirm.classList.toggle('is-pressed', step === 5);
    }

    const confirmed = root.querySelector<HTMLElement>('[data-confirmed]');
    if (confirmed) confirmed.classList.toggle('is-in', step >= 6);
}

function renderWaitlist(v: Vertical): string {
    const staff2 = firstName(v.staff[1].name);
    const queue = [
        [v.waitFirst, v.waitWants],
        [v.q2, v.q2w],
        [v.q3, v.q3w],
    ]
        .map(
            ([name, wants], i) =>
                `<div class="dm-q" data-q="${i}">` +
                `<span class="dm-mono dm-q-rank">#${i + 1}</span>` +
                `<span class="dm-q-main"><span class="dm-q-name">${esc(name)}</span>` +
                `<span class="dm-mono dm-q-wants">${esc(wants)}</span></span>` +
                `<span class="dm-mono dm-q-state">${i === 0 ? '2 d 04 h' : ['5 d 11 h', '1 d 02 h'][i - 1]}</span>` +
                '</div>',
        )
        .join('');

    return (
        '<div class="dm-act dm-act-waitlist">' +
        '<div class="dm-wl-left">' +
        '<div class="dm-head"><h3 class="dm-h3 dm-h3-tight">Thu 11 Sep</h3>' +
        '<span class="dm-mono dm-quiet">3 people waiting</span></div>' +
        '<div class="dm-wl-slot" data-wl-slot>' +
        '<div class="dm-wl-pulse" data-wl-pulse></div>' +
        '<div class="dm-wl-slot-inner">' +
        '<div class="dm-wl-slot-main">' +
        '<div class="dm-wl-slot-top"><span class="dm-mono dm-wl-time" data-wl-time>11:00</span>' +
        `<span class="dm-mono dm-quiet">${esc(v.dur)} · ${esc(staff2)}</span></div>` +
        `<div class="dm-wl-name" data-wl-name>${esc(v.cancelName)}</div>` +
        `<div class="dm-mono dm-quiet" data-wl-service>${esc(v.service)} · deposit ${esc(v.dep)} paid</div>` +
        '</div>' +
        '<span class="dm-pill" data-wl-pill>Confirmed</span>' +
        '</div></div>' +
        '<div class="dm-payoff" data-payoff>' +
        '<div class="dm-payoff-rule" data-payoff-rule></div>' +
        '<div class="dm-payoff-row"><div class="dm-mono dm-payoff-fig">4 min</div>' +
        '<div class="dm-payoff-copy">Cancellation to rebooked.<br>No calls made.</div></div>' +
        '<div class="dm-eyebrow dm-payoff-note">Filled from the waitlist · deposit taken</div>' +
        '</div>' +
        '<div class="dm-eyebrow dm-q-head">Waitlist queue</div>' +
        `<div class="dm-q-list">${queue}</div>` +
        '</div>' +
        '<div class="dm-wl-divider"></div>' +
        '<div class="dm-wl-right">' +
        `<div class="dm-eyebrow">Messages · ${esc(v.waitFirst)}</div>` +
        '<div class="dm-thread">' +
        '<div class="dm-sms-out" data-sms-out><div class="dm-sms-type">' +
        `<div class="dm-sms-bubble">Your 11:00 Thu slot just opened up. Reply YES to book — deposit ${esc(v.dep)}.</div>` +
        '</div><div class="dm-mono dm-sms-meta">Sent 14:02 · automatic</div></div>' +
        '<div class="dm-typing" data-sms-typing><span></span><span></span><span></span></div>' +
        '<div class="dm-sms-reply" data-sms-reply><div class="dm-sms-yes">YES</div>' +
        '<div class="dm-mono dm-sms-meta">Received 14:06</div></div>' +
        '</div>' +
        '<div class="dm-wl-foot">DiaryDesk offers the slot to the queue in order and takes the deposit on reply.</div>' +
        '</div></div>'
    );
}

function syncWaitlist(root: HTMLElement, step: number, v: Vertical): void {
    const cancelled = step >= 1;
    const refilled = step >= 5;

    const slot = root.querySelector<HTMLElement>('[data-wl-slot]');
    if (slot) {
        slot.classList.toggle('is-cancelled', cancelled && !refilled);
        slot.classList.toggle('is-refilled', refilled);
    }

    const pulse = root.querySelector<HTMLElement>('[data-wl-pulse]');
    if (pulse) pulse.classList.toggle('is-on', refilled);

    const pill = root.querySelector<HTMLElement>('[data-wl-pill]');
    if (pill) {
        pill.textContent = refilled ? 'Booked' : cancelled ? (step >= 2 ? 'Notifying waitlist' : 'Cancelled') : 'Confirmed';
        pill.classList.toggle('is-refilled', refilled);
    }

    const name = root.querySelector<HTMLElement>('[data-wl-name]');
    if (name) name.textContent = refilled ? v.waitName : v.cancelName;

    const service = root.querySelector<HTMLElement>('[data-wl-service]');
    if (service) {
        service.textContent = refilled
            ? `${v.service} · deposit ${v.dep} paid`
            : cancelled
              ? 'Cancelled 14:02 · deposit refunded'
              : `${v.service} · deposit ${v.dep} paid`;
    }

    const first = root.querySelector<HTMLElement>('[data-q="0"]');
    if (first) {
        first.classList.toggle('is-live', step >= 2);
        first.classList.toggle('is-spent', refilled);
        const state = first.querySelector<HTMLElement>('.dm-q-state');
        if (state) state.textContent = refilled ? 'Booked' : step >= 4 ? 'Replied' : step >= 2 ? 'Offered' : '2 d 04 h';
    }

    root.querySelector<HTMLElement>('[data-sms-out]')?.classList.toggle('is-on', step >= 2);
    root.querySelector<HTMLElement>('[data-sms-typing]')?.classList.toggle('is-on', step === 3);
    root.querySelector<HTMLElement>('[data-sms-reply]')?.classList.toggle('is-on', step >= 4);
    root.querySelector<HTMLElement>('[data-payoff]')?.classList.toggle('is-in', step >= 6);
    root.querySelector<HTMLElement>('[data-payoff-rule]')?.classList.toggle('is-in', step >= 6);
}

function renderDiary(v: Vertical): string {
    const staff1 = firstName(v.staff[0].name);
    const staff2 = firstName(v.staff[1].name);
    const rows: Array<[string, Array<{ name: string; sub: string; tagged?: boolean } | null>]> = [
        ['9:00', [{ name: v.cancelFirst === 'Coco' ? 'Coco Marsh' : v.cancelName, sub: `${v.svc2} · ${v.dur2}` }, null]],
        ['10:00', [null, { name: v.d10, sub: `${v.svc3} · ${v.dur3}` }]],
        [
            '11:00',
            [
                { name: v.d11a, sub: `${v.service} · ${v.dur}` },
                { name: v.d11b, sub: `${v.service} · ${v.dur}`, tagged: true },
            ],
        ],
        ['12:00', [null, null]],
        ['13:00', [{ name: v.d13, sub: `${v.svc2} · ${v.dur2}` }, null]],
    ];

    const body = rows
        .map(([time, cells], i) => {
            const cellHtml = cells
                .map((c) =>
                    c
                        ? `<div class="dm-cell${c.tagged ? ' is-tagged' : ''}"><div class="dm-cell-top">` +
                          `<span class="dm-cell-name">${esc(c.name)}</span>` +
                          (c.tagged ? '<span class="dm-tag">waitlist-filled</span>' : '') +
                          `</div><div class="dm-mono dm-cell-sub">${esc(c.sub)}</div></div>`
                        : '<div class="dm-cell is-open"><div class="dm-cell-top"><span class="dm-cell-name">Open</span></div>' +
                          '<div class="dm-mono dm-cell-sub">Bookable online</div></div>',
                )
                .join('');
            return `<div class="dm-drow" style="animation-delay:${i * 130}ms"><div class="dm-mono dm-drow-time">${esc(time)}</div>${cellHtml}</div>`;
        })
        .join('');

    return (
        '<div class="dm-act dm-act-diary">' +
        '<div class="dm-head"><h3 class="dm-h3 dm-h3-tight">Thursday 11 September</h3>' +
        '<span class="dm-mono dm-quiet">5 appointments · 2 slots open</span></div>' +
        `<div class="dm-dhead"><div></div><div class="dm-dhead-name">${esc(staff1)}</div><div class="dm-dhead-name">${esc(staff2)}</div></div>` +
        body +
        '<div class="dm-dfoot">' +
        `<div><div class="dm-eyebrow">Taken today</div><div class="dm-mono dm-dfoot-fig">${esc(v.dayTotal)}</div></div>` +
        `<div><div class="dm-eyebrow">Deposits held</div><div class="dm-mono dm-dfoot-fig">${esc(v.dayDeposits)}</div></div>` +
        '<div><div class="dm-eyebrow">Filled from waitlist</div><div class="dm-mono dm-dfoot-fig is-accent">1 of 5</div></div>' +
        '<div class="dm-dfoot-note">Open slots stay bookable online until the moment they start.</div>' +
        '</div></div>'
    );
}

function renderNoShow(v: Vertical): string {
    const staff1 = firstName(v.staff[0].name);
    const staff2 = firstName(v.staff[1].name);
    const menu = ['Reschedule', 'Message customer', 'Mark as no-show', 'Cancel booking']
        .map(
            (label) =>
                `<div class="dm-menu-item"${label === 'Mark as no-show' ? ' data-cursor="nsitem" data-menu-target' : ''}>${esc(label)}</div>`,
        )
        .join('');

    const rest = [
        { time: '11:00', name: `${v.d11a} — ${v.service.toLowerCase()}`, sub: `with ${staff1} · ${v.dur}`, amount: v.price },
        { time: '11:00', name: `${v.d11b} — ${v.service.toLowerCase()}`, sub: `with ${staff2} · ${v.dur} · waitlist-filled`, amount: v.price },
    ]
        .map(
            (r) =>
                '<div class="dm-nsrow">' +
                `<div class="dm-mono dm-nsrow-time">${esc(r.time)}</div>` +
                `<div class="dm-nsrow-main"><div class="dm-strong">${esc(r.name)}</div>` +
                `<div class="dm-mono dm-nsrow-sub">${esc(r.sub)}</div></div>` +
                '<span class="dm-pill">Confirmed</span>' +
                `<div class="dm-mono dm-nsrow-amt">${esc(r.amount)}</div><div class="dm-nsrow-dots"></div></div>`,
        )
        .join('');

    return (
        '<div class="dm-act dm-act-noshow">' +
        '<div class="dm-head"><h3 class="dm-h3 dm-h3-tight">Thursday 11 September</h3>' +
        `<span class="dm-mono dm-quiet">09:12 · ${esc(v.cancelLate)}</span></div>` +
        '<div class="dm-nslist">' +
        '<div class="dm-nsrow dm-nsrow-target" data-ns-row>' +
        '<div class="dm-mono dm-nsrow-time">9:00</div>' +
        '<div class="dm-nsrow-main"><div class="dm-nsrow-nameWrap">' +
        `<span class="dm-strong" data-ns-name>${esc(v.cancelName)} · ${esc(v.svc2.toLowerCase())}</span>` +
        '<span class="dm-strike" data-ns-strike></span></div>' +
        `<div class="dm-mono dm-nsrow-sub">with ${esc(staff1)} · ${esc(v.dur2)} · deposit ${esc(v.dep2)} held</div></div>` +
        '<span class="dm-pill" data-ns-pill>Confirmed</span>' +
        `<div class="dm-mono dm-nsrow-amt">${esc(v.price2)}</div>` +
        '<div class="dm-nsrow-dots" data-cursor="dots" data-ns-dots>⋯</div>' +
        `<div class="dm-menu" data-ns-menu>${menu}</div>` +
        '</div>' +
        rest +
        '</div>' +
        '<div class="dm-nsnote" data-ns-note><span class="dm-tag">Auto</span>' +
        `<span class="dm-nsnote-copy">Waitlist notified · deposit of <span class="dm-mono dm-accent">${esc(v.dep2)}</span> kept, nobody chased</span></div>` +
        '</div>'
    );
}

function syncNoShow(root: HTMLElement, step: number): void {
    const done = step >= 5;

    root.querySelector<HTMLElement>('[data-ns-row]')?.classList.toggle('is-done', done);
    root.querySelector<HTMLElement>('[data-ns-strike]')?.classList.toggle('is-on', done);
    root.querySelector<HTMLElement>('[data-ns-menu]')?.classList.toggle('is-open', step >= 2 && step <= 4);
    root.querySelector<HTMLElement>('[data-menu-target]')?.classList.toggle('is-on', step >= 3);
    root.querySelector<HTMLElement>('[data-ns-dots]')?.classList.toggle('is-live', step >= 1 && step <= 4);
    root.querySelector<HTMLElement>('[data-ns-note]')?.classList.toggle('is-in', step >= 6);

    const pill = root.querySelector<HTMLElement>('[data-ns-pill]');
    if (pill) {
        pill.textContent = done ? 'No-show' : 'Confirmed';
        pill.classList.toggle('is-done', done);
    }
}

function renderStaff(v: Vertical): string {
    const rows = v.staff
        .map((p, i) => {
            const week = WEEK_LETTERS.map(
                (label, j) =>
                    `<div class="dm-wd"><div class="dm-mono dm-wd-label">${esc(label)}</div>` +
                    `<div class="dm-wd-bar${p.on[j] ? ' is-on' : ''}"></div></div>`,
            ).join('');
            return (
                `<div class="dm-srow" style="animation-delay:${i * 90}ms">` +
                `<div class="dm-mono dm-avatar">${esc(p.initial)}</div>` +
                `<div class="dm-srow-id"><div class="dm-srow-name">${esc(p.name)}</div>` +
                `<div class="dm-srow-role">${esc(p.role)}</div></div>` +
                `<div class="dm-srow-week"><div class="dm-week">${week}</div>` +
                `<div class="dm-mono dm-srow-hours">${esc(p.hours)}</div></div>` +
                `<div class="dm-srow-booked"><div class="dm-mono dm-srow-pct">${esc(p.booked)}</div>` +
                '<div class="dm-eyebrow">Booked</div></div>' +
                '<div class="dm-srow-edit">Edit hours</div></div>'
            );
        })
        .join('');

    return (
        '<div class="dm-act dm-act-staff">' +
        '<div class="dm-head"><h3 class="dm-h3 dm-h3-tight">Who is working</h3>' +
        '<span class="dm-mono dm-quiet">Week of 8 Sep</span></div>' +
        `<div class="dm-slist">${rows}</div>` +
        '<div class="dm-sfoot">' +
        `<div class="dm-mono dm-quiet">Cover: ${v.staff.length} staff · ${coverHours(v.staff)} h · no gaps in opening hours</div>` +
        '<div class="dm-sfoot-note">Hours drive what customers can book — change a shift and the portal updates instantly.</div>' +
        '</div></div>'
    );
}

function renderLoyalty(v: Vertical): string {
    const track = STAMP_DATES.map(
        (date, i) =>
            `<div class="dm-stamp" data-stamp="${i}"><div class="dm-mono dm-stamp-date">${esc(date)}</div>` +
            `<div class="dm-mono dm-stamp-idx">${String(i + 1).padStart(2, '0')}</div></div>`,
    ).join('');

    return (
        '<div class="dm-act dm-act-loyalty">' +
        '<div class="dm-loyal-col">' +
        `<div class="dm-eyebrow">Loyalty · ${esc(v.loyalSubj)}</div>` +
        `<div class="dm-track">${track}</div>` +
        '<div class="dm-loyal-copy">' +
        `<div class="dm-h3" data-loyal-reward>3 more ${esc(v.loyalUnit)} until ${esc(v.loyalReward)}</div>` +
        `<div class="dm-mono dm-accent dm-loyal-line" data-loyal-line>5 of 8 ${esc(v.loyalUnit)} stamped · last 11 Sep</div>` +
        `<div class="dm-loyal-note">The reward comes off the next ${esc(v.service)} automatically.</div>` +
        '</div>' +
        '<div class="dm-loyal-grid">' +
        '<div><div class="dm-eyebrow">Counted</div><div class="dm-loyal-cell">Automatically, the moment an appointment completes.</div></div>' +
        '<div><div class="dm-eyebrow">Redeemed</div><div class="dm-loyal-cell">Applied at checkout — no card to carry or lose.</div></div>' +
        `<div><div class="dm-eyebrow">Last visit</div><div class="dm-mono dm-loyal-cell">11 Sep · ${esc(v.service)}</div></div>` +
        '</div></div></div>'
    );
}

function syncLoyalty(root: HTMLElement, step: number, v: Vertical): void {
    const filled = step >= 1;
    const count = filled ? 6 : 5;

    root.querySelectorAll<HTMLElement>('.dm-stamp').forEach((el, i) => {
        const fresh = i === 5 && filled;
        el.classList.toggle('is-stamped', i < count);
        el.classList.toggle('is-fresh', fresh);
        const date = el.querySelector<HTMLElement>('.dm-stamp-date');
        if (date) date.textContent = i < count || fresh ? STAMP_DATES[i] : '';
    });

    const reward = root.querySelector<HTMLElement>('[data-loyal-reward]');
    if (reward) reward.textContent = `${8 - count} more ${v.loyalUnit} until ${v.loyalReward}`;

    const line = root.querySelector<HTMLElement>('[data-loyal-line]');
    if (line) line.textContent = `${count} of 8 ${v.loyalUnit} stamped · last 11 Sep`;
}

const RENDERERS = [renderBookingPortal, renderWaitlist, renderDiary, renderNoShow, renderStaff, renderLoyalty];

function clockFor(act: number, step: number): string {
    if (act === 0) return '13:58';
    if (act === 1) return step >= 4 ? '14:06' : '14:02';
    if (act === 2) return '14:07';
    if (act === 3) return '09:12';
    if (act === 4) return '17:40';
    return '18:05';
}

function start(section: HTMLElement): void {
    const stage = section.querySelector<HTMLElement>('[data-dm-stage]');
    const cursor = section.querySelector<HTMLElement>('[data-dm-cursor]');
    const context = section.querySelector<HTMLElement>('[data-dm-context]');
    const biz = section.querySelector<HTMLElement>('[data-dm-biz]');
    const clock = section.querySelector<HTMLElement>('[data-dm-clock]');
    const scrub = section.querySelector<HTMLElement>('[data-dm-scrub]');
    const switcher = section.querySelector<HTMLElement>('[data-dm-switcher]');

    if (!stage || !cursor || !context || !biz || !clock || !scrub || !switcher) return;

    const still = window.matchMedia('(prefers-reduced-motion: reduce)');
    let vertical = VERTICALS[0];
    let act = -1;
    let step = -1;
    let origin = 0;
    let raf = 0;

    const paint = (nextAct: number, nextStep: number): void => {
        if (nextAct !== act) {
            stage.innerHTML = RENDERERS[nextAct](vertical);
            context.textContent = ACTS[nextAct].context;
            scrub.querySelectorAll<HTMLElement>('.dm-scrub-cell').forEach((cell, i) => {
                cell.classList.toggle('is-on', i === nextAct);
            });
        }

        act = nextAct;
        step = nextStep;
        biz.textContent = vertical.biz;
        clock.textContent = clockFor(act, step);

        const root = stage.firstElementChild as HTMLElement | null;
        if (!root) return;

        if (act === 0) syncBookingPortal(root, step, vertical);
        else if (act === 1) syncWaitlist(root, step, vertical);
        else if (act === 3) syncNoShow(root, step);
        else if (act === 5) syncLoyalty(root, step, vertical);

        placeCursor(root);
    };

    const placeCursor = (root: HTMLElement): void => {
        const showing = act === 0 || act === 3;
        cursor.classList.toggle('is-on', showing && !still.matches);
        cursor.classList.toggle('is-pressing', (act === 0 && step === 5) || (act === 3 && step === 4));
        if (!showing) return;

        let key = '';
        let pct: [number, number] = [0.8, 0.18];
        if (act === 0) {
            key = step >= 4 ? 'confirm' : step >= 1 ? 'slot11' : '';
        } else {
            key = step >= 3 ? 'nsitem' : step >= 1 ? 'dots' : '';
            pct = [0.74, 0.12];
        }

        const box = stage.getBoundingClientRect();
        if (!box.width || !box.height) return;

        if (key) {
            const target = root.querySelector<HTMLElement>(`[data-cursor="${key}"]`);
            if (!target) return;
            const r = target.getBoundingClientRect();
            cursor.style.left = `${Math.round(r.left - box.left + r.width / 2 - 5.5)}px`;
            cursor.style.top = `${Math.round(r.top - box.top + r.height / 2 - 5.5)}px`;
        } else {
            cursor.style.left = `${Math.round(box.width * pct[0])}px`;
            cursor.style.top = `${Math.round(box.height * pct[1])}px`;
        }
    };

    const frame = (): void => {
        const t = (performance.now() - origin) % TOTAL;
        let a = 0;
        while (a < ACTS.length - 1 && t >= STARTS[a] + ACTS[a].dur) a++;
        const local = t - STARTS[a];
        const marks = ACTS[a].steps;
        let s = 0;
        while (s < marks.length - 1 && local >= marks[s + 1]) s++;

        scrub.querySelectorAll<HTMLElement>('.dm-scrub-bar').forEach((bar, i) => {
            bar.style.width = `${i < a ? 100 : i > a ? 0 : Math.min(100, (local / ACTS[a].dur) * 100)}%`;
        });

        if (a !== act || s !== step) paint(a, s);
        raf = window.requestAnimationFrame(frame);
    };

    const run = (): void => {
        if (still.matches) {
            paint(1, ACTS[1].steps.length - 1);
            scrub.querySelectorAll<HTMLElement>('.dm-scrub-bar').forEach((bar, i) => {
                bar.style.width = i <= 1 ? '100%' : '0%';
            });
            return;
        }
        paint(0, 0);
        origin = performance.now();
        raf = window.requestAnimationFrame(frame);
    };

    const stop = (): void => {
        if (raf) window.cancelAnimationFrame(raf);
        raf = 0;
    };

    switcher.querySelectorAll<HTMLButtonElement>('[data-dm-vertical]').forEach((button) => {
        button.addEventListener('click', () => {
            const next = VERTICALS.find((v) => v.label === button.dataset.dmVertical);
            if (!next || next === vertical) return;
            vertical = next;
            switcher.querySelectorAll<HTMLButtonElement>('[data-dm-vertical]').forEach((b) => {
                const on = b === button;
                b.classList.toggle('is-on', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            const current = act;
            act = -1;
            paint(current === -1 ? 0 : current, step === -1 ? 0 : step);
        });
    });

    let inView = false;

    const resume = (): void => {
        if (raf || still.matches || !inView || document.hidden) return;
        origin = performance.now() - (act >= 0 ? STARTS[act] : 0);
        raf = window.requestAnimationFrame(frame);
    };

    const observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                inView = entry.isIntersecting;
                if (inView) resume();
                else stop();
            }
        },
        { threshold: 0.15 },
    );

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) stop();
        else resume();
    });

    window.addEventListener('resize', () => {
        const root = stage.firstElementChild as HTMLElement | null;
        if (root) placeCursor(root);
    });

    run();
    observer.observe(section);
}

const section = document.querySelector<HTMLElement>('[data-dm-section]');
if (section) start(section);
