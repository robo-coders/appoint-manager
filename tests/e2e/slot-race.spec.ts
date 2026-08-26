import { expect, test } from '@playwright/test';
import { DEMO, bookViaApi, currentProposal, fillDetails, freshContext, proposalProps } from './support';

/**
 * The 409 slot race, end to end.
 *
 * The phase 7 report listed this as the weakest thing in the suite: *"the
 * controller returns it and the island handles it, but nothing exercises the two
 * together."* It is also the guarantee the whole product rests on — a waitlist
 * that offers one slot to five people is only honest if exactly one of them can
 * take it, and the loser has to be told in a way that keeps them.
 *
 * Two browser contexts, so two customers who have never met. Both are shown the
 * same proposal, both press Reserve, and the assertions are about what each of
 * them then sees — not about what the database contains, which is the part
 * `WaitlistTest` already covers at the unit level.
 */
test.describe('two customers, one slot', () => {
    test('exactly one wins, and the loser is offered another appointment', async ({ browser }) => {
        const setup = await freshContext(browser);
        await setup.goto(`/book/${DEMO.slug}`);

        /*
         * Leave exactly one groomer free at the proposed time.
         *
         * This is the step that makes the race a race, and finding out it was
         * needed is the most interesting thing this spec did. Two browsers
         * racing a naive slot produce **two 201s** — not because the lock fails,
         * but because it is never reached: `PublicBookingController::resolveStaff`
         * finds the requested groomer taken and quietly hands the second
         * customer whoever else is free. The lock only decides anything once the
         * slot is down to its last groomer, which is the case this arranges.
         */
        const target = await proposalProps(setup);
        const others = target.staff_ids.filter((id) => id !== target.staff_id);

        for (const [index, staffId] of others.entries()) {
            const status = await bookViaApi(setup, DEMO.slug, {
                service_id: target.service_id,
                starts_at: target.starts_at,
                staff_id: staffId,
                name: `Filler ${index}`,
                email: `filler-${index}@example.test`,
            });

            expect(status, 'setup booking should have succeeded').toBe(201);
        }

        await setup.context().close();

        const alice = await freshContext(browser);
        const bob = await freshContext(browser);

        await alice.goto(`/book/${DEMO.slug}`);
        await bob.goto(`/book/${DEMO.slug}`);

        // Both are looking at the same appointment, with the same groomer. If
        // they are not, the rest of this proves nothing.
        const proposal = await currentProposal(alice);
        expect(await currentProposal(bob)).toEqual(proposal);
        expect((await proposalProps(bob)).staff_id).toBe((await proposalProps(alice)).staff_id);
        expect((await proposalProps(alice)).staff_ids).toHaveLength(1);

        await fillDetails(alice, 'Alice Nowak', 'alice@example.test');
        await fillDetails(bob, 'Bob Reilly', 'bob@example.test');

        /*
         * As close to simultaneous as two browsers get. The race is decided by
         * `lockStaffWindow()` in the database, not by which click landed first,
         * so the interleaving does not have to be perfect — only genuinely
         * concurrent.
         */
        const [aliceResponse, bobResponse] = await Promise.all([
            alice.waitForResponse((r) => r.url().includes('/bookings') && r.request().method() === 'POST'),
            bob.waitForResponse((r) => r.url().includes('/bookings') && r.request().method() === 'POST'),
            alice.getByRole('button', { name: /^Reserve / }).click(),
            bob.getByRole('button', { name: /^Reserve / }).click(),
        ]);

        const statuses = [aliceResponse.status(), bobResponse.status()].sort();

        // One created, one told the slot has gone. Never two 201s, and never
        // two 409s: a race that loses both bookings is as wrong as one that
        // takes both.
        expect(statuses).toEqual([201, 409]);

        const loser = aliceResponse.status() === 409 ? alice : bob;
        const winner = aliceResponse.status() === 409 ? bob : alice;

        /*
         * The loser is told plainly, and the message survives the picker
         * opening underneath it — it used not to, which is what this caught.
         * It is a `status`, not an `alert`: losing a race is the mechanic
         * working, not something the customer did wrong.
         */
        const told = loser.getByRole('status');
        await expect(told).toBeVisible();
        await expect(told).toContainText(/just taken/i);
        await expect(told).not.toHaveClass(/text-danger/);

        /*
         * And they are given somewhere to go. The picker opens on its own — the
         * point of the designed taken state is that it proposes rather than
         * apologises.
         */
        await expect(loser.getByRole('heading', { name: 'Pick a day' })).toBeVisible();
        await expect(loser.getByRole('group', { name: /Week of/ })).toBeVisible();

        /*
         * And the slot that has gone is struck through in place rather than
         * removed — an empty grid reads as a broken page, and "09:00, taken"
         * carries the reason in the accessible name rather than in a line.
         */
        await expect(loser.getByRole('button', { name: `${proposal.time}, taken` })).toBeVisible();

        // The winner is through to the confirmation, not left in limbo.
        await expect(winner.getByText(/You’re booked|Pay the deposit/)).toBeVisible();

        await alice.context().close();
        await bob.context().close();
    });

    /*
     * A booking takes one *groomer's* slot, not the time itself.
     *
     * The first version of this test asserted that the next visitor is offered a
     * different time, and it failed — correctly. The demo salon has four
     * groomers, so 09:00 stays bookable until all four are busy, and a proposal
     * is an appointment (a time *and* a person) rather than a time. Asserting on
     * the time alone would have made a passing test out of a wrong belief.
     */
    test('a booking takes one groomer’s slot, not the whole time', async ({ browser }) => {
        const page = await freshContext(browser);

        await page.goto(`/book/${DEMO.slug}`);
        const before = await currentProposal(page);
        const staffBefore = await page.locator('p.caption').first().innerText();

        await fillDetails(page, 'Carla Dunne', 'carla@example.test');
        await page.getByRole('button', { name: /^Reserve / }).click();
        await expect(page.getByText(/You’re booked|Pay the deposit/)).toBeVisible();

        const next = await freshContext(page.context().browser()!);
        await next.goto(`/book/${DEMO.slug}`);

        const after = await currentProposal(next);
        const staffAfter = await next.locator('p.caption').first().innerText();

        // The appointment on offer is not the one that was just taken: either
        // the time moved, or the groomer did.
        expect(`${after.day} ${after.time} ${staffAfter}`).not.toBe(`${before.day} ${before.time} ${staffBefore}`);

        await page.context().close();
        await next.context().close();
    });
});
