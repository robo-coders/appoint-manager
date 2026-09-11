import { expect, test } from '@playwright/test';
import { DEMO, bookViaApi, currentProposal, fillDetails, freshContext, proposalProps } from './support';

test.describe('two customers, one slot', () => {
    test('exactly one wins, and the loser is offered another appointment', async ({ browser }) => {
        const setup = await freshContext(browser);
        await setup.goto(`/book/${DEMO.slug}`);

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

        const proposal = await currentProposal(alice);
        expect(await currentProposal(bob)).toEqual(proposal);
        expect((await proposalProps(bob)).staff_id).toBe((await proposalProps(alice)).staff_id);
        expect((await proposalProps(alice)).staff_ids).toHaveLength(1);

        await fillDetails(alice, 'Alice Nowak', 'alice@example.test');
        await fillDetails(bob, 'Bob Reilly', 'bob@example.test');

        const [aliceResponse, bobResponse] = await Promise.all([
            alice.waitForResponse((r) => r.url().includes('/bookings') && r.request().method() === 'POST'),
            bob.waitForResponse((r) => r.url().includes('/bookings') && r.request().method() === 'POST'),
            alice.getByRole('button', { name: /^Reserve / }).click(),
            bob.getByRole('button', { name: /^Reserve / }).click(),
        ]);

        const statuses = [aliceResponse.status(), bobResponse.status()].sort();

        expect(statuses).toEqual([201, 409]);

        const loser = aliceResponse.status() === 409 ? alice : bob;
        const winner = aliceResponse.status() === 409 ? bob : alice;

        const told = loser.getByRole('status');
        await expect(told).toBeVisible();
        await expect(told).toContainText(/just taken/i);
        await expect(told).not.toHaveClass(/text-danger/);

        await expect(loser.getByRole('heading', { name: 'Pick a day' })).toBeVisible();
        await expect(loser.getByRole('group', { name: /Week of/ })).toBeVisible();

        await expect(loser.getByRole('button', { name: `${proposal.time}, taken` })).toBeVisible();

        await expect(winner.getByText(/You’re booked|Pay the deposit/)).toBeVisible();

        await alice.context().close();
        await bob.context().close();
    });

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

        expect(`${after.day} ${after.time} ${staffAfter}`).not.toBe(`${before.day} ${before.time} ${staffBefore}`);

        await page.context().close();
        await next.context().close();
    });
});
