import { expect, test, type Page } from "@playwright/test";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

const LOYALTY = "/settings/loyalty";
const VISUAL_CHECK = ".design/mockups/Backend/visual-check";

const decode = (value: string): string =>
    value
        .replace(/&quot;/g, '"')
        .replace(/&#039;/g, "'")
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">")
        .replace(/&amp;/g, "&");

const encode = (value: string): string =>
    value.replace(/&/g, "&amp;").replace(/"/g, "&quot;");

async function openLoyalty(
    page: Page,
    rewrite?: (props: Record<string, unknown>) => void,
): Promise<void> {
    if (rewrite) {
        await page.route(
            `**${LOYALTY}`,
            async (route) => {
                const response = await route.fetch();
                const html = await response.text();
                const encoded = html.match(/data-page="([^"]*)"/)?.[1];

                if (encoded === undefined) {
                    await route.fulfill({ response, body: html });

                    return;
                }

                const payload = JSON.parse(decode(encoded));

                rewrite(payload.props);

                await route.fulfill({
                    response,
                    body: html.replace(
                        encoded,
                        encode(JSON.stringify(payload)),
                    ),
                });
            },
            { times: 1 },
        );
    }

    await page.goto(LOYALTY);
    await expect(
        page.getByRole("switch", { name: /Run a loyalty card/ }),
    ).toBeVisible();
}

test.describe("the loyalty settings tab", () => {
    test("saves a scheme and says so", async ({ page }) => {
        await openLoyalty(page, (props) => {
            const loyalty = props.loyalty as Record<string, unknown>;
            loyalty.enabled = false;
        });

        await page.getByRole("switch", { name: /Run a loyalty card/ }).click();

        const name = page.getByLabel(/Card name/);
        await name.fill("Clip club");

        await page.getByLabel(/^Reward/).fill("The next groom is on us");

        const before = await page.getByTestId("visits-count").textContent();
        await page.getByRole("button", { name: /One more/ }).click();
        await expect(page.getByTestId("visits-count")).not.toHaveText(
            before ?? "",
        );

        await page.getByRole("button", { name: "Save", exact: true }).click();

        await expect(page.getByText("Changes saved.")).toBeVisible();

        await page.reload();

        await expect(page.getByLabel(/Card name/)).toHaveValue("Clip club");
        await expect(page.getByLabel(/^Reward/)).toHaveValue(
            "The next groom is on us",
        );
        await expect(
            page.getByRole("switch", { name: /Run a loyalty card/ }),
        ).toHaveAttribute("aria-checked", "true");
    });

    test("asks before pausing cards that are part-way through", async ({
        page,
    }) => {
        await openLoyalty(page, (props) => {
            const loyalty = props.loyalty as Record<string, unknown>;
            loyalty.enabled = true;
            loyalty.enrolled = 12;
        });

        await page.getByRole("switch", { name: /Run a loyalty card/ }).click();

        const dialog = page.getByRole("alertdialog");

        await expect(dialog).toBeVisible();
        await expect(dialog).toContainText("paused, not deleted");
        await expect(dialog).toContainText("12");

        await dialog.getByRole("button", { name: "Leave it on" }).click();

        await expect(
            page.getByRole("switch", { name: /Run a loyalty card/ }),
        ).toHaveAttribute("aria-checked", "true");

        await page.getByRole("switch", { name: /Run a loyalty card/ }).click();
        await page.getByRole("button", { name: "Switch it off" }).click();

        await expect(
            page.getByRole("switch", { name: /Run a loyalty card/ }),
        ).toHaveAttribute("aria-checked", "false");
    });

    test("offers all services only, and where to add some, when a salon has none", async ({
        page,
    }) => {
        await openLoyalty(page, (props) => {
            props.services = [];
        });

        const select = page.getByLabel("Applies to");

        await expect(select.locator("option")).toHaveCount(1);
        await expect(select.locator("option")).toHaveText(["All services"]);
        await expect(
            page.getByRole("link", {
                name: "Add services to scope this to one",
            }),
        ).toBeVisible();
    });

    test("offers all services first when a salon has some", async ({
        page,
    }) => {
        await openLoyalty(page);

        const options = page.getByLabel("Applies to").locator("option");

        await expect(options.first()).toHaveText("All services");
        expect(await options.count()).toBeGreaterThan(1);
    });

    test("counts the reward down and refuses to save an over-long one", async ({
        page,
    }) => {
        await openLoyalty(page, (props) => {
            const loyalty = props.loyalty as Record<string, unknown>;
            loyalty.enabled = true;
        });

        await page.getByLabel(/^Reward/).fill("a".repeat(130));

        await expect(
            page.getByText("That reward is too long to save"),
        ).toBeVisible();
        await expect(
            page.getByRole("button", { name: "Save", exact: true }),
        ).toBeDisabled();
    });

    test("warns before a shorter card completes people", async ({ page }) => {
        await openLoyalty(page, (props) => {
            const loyalty = props.loyalty as Record<string, unknown>;
            loyalty.enabled = true;
            loyalty.sessions_required = 6;
            props.progress = { 5: 4 };
        });

        await page.getByRole("button", { name: /One fewer/ }).click();

        await expect(
            page.getByText("A shorter card completes people straight away"),
        ).toBeVisible();
    });
});

const MOCKUP = pathToFileURL(
    resolve(".design/mockups/settings/loyalty-final.dc.html"),
).href;

test.describe("visual check artefacts", () => {
    test("captures loyalty-settings-app.png", async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 1000 });
        await openLoyalty(page, (props) => {
            const loyalty = props.loyalty as Record<string, unknown>;
            loyalty.enabled = true;
            loyalty.name = "Full groom tier";
            loyalty.reward = "The next full groom is free";
            loyalty.sessions_required = 10;
        });

        await page.screenshot({
            path: `${VISUAL_CHECK}/loyalty-settings-app.png`,
            fullPage: true,
        });
    });

    test("captures loyalty-settings-full-card-app.png", async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 1000 });
        await openLoyalty(page, (props) => {
            const loyalty = props.loyalty as Record<string, unknown>;
            loyalty.enabled = true;
            loyalty.name = "Full groom tier";
            loyalty.reward = "The next full groom is free";
            loyalty.sessions_required = 10;
        });

        await page.getByRole("button", { name: "Show a full card" }).click();
        await expect(page.getByText("Free session ready")).toBeVisible();

        await page.screenshot({
            path: `${VISUAL_CHECK}/loyalty-settings-full-card-app.png`,
            fullPage: true,
        });
    });

    test("captures loyalty-settings-mockup.png", async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 1000 });
        await page.goto(MOCKUP);

        const artboard = page.locator('[id="4a"]');

        await expect(artboard).toBeVisible();
        await expect(artboard.getByText("Free session ready")).toBeVisible();

        await artboard.screenshot({
            path: `${VISUAL_CHECK}/loyalty-settings-mockup.png`,
        });
    });
});
