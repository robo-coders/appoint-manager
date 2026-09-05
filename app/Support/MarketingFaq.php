<?php

namespace App\Support;

/**
 * The marketing site's questions and answers, written once.
 *
 * **This exists because an FAQ has to be published twice.** Once as readable
 * HTML for a person, and once as `FAQPage` JSON-LD for an answer engine — and
 * Google's structured-data policy is explicit that the markup must match the
 * visible text. Two copies of the same paragraph in two formats is the exact
 * shape of drift this repository already refuses for prices (see
 * `MarketingFigures`): the page would be edited, the markup would not, and the
 * version quoted back at a searcher would be the stale one.
 *
 * So the questions live here, the Blade partial renders them, and
 * `MarketingSchema` serialises the same array. There is one place to edit an
 * answer and no way to edit only half of it.
 *
 * Every figure inside an answer comes off `MarketingFigures`, so a price change
 * moves the FAQ and its structured data at the same time.
 *
 * **Answers may contain a link and nothing else.** They are rendered unescaped,
 * which is safe here and only here: this file is author-written source, there is
 * no user input anywhere near it, and `schema.org` permits HTML in
 * `acceptedAnswer.text`. Anything richer than an `<a>` belongs on the page as
 * prose, not in an answer.
 */
final class MarketingFaq
{
    public function __construct(private MarketingFigures $figures) {}

    /**
     * The home page's questions.
     *
     * Written for the two readers at once: a groomer deciding whether this is
     * for her, and an answer engine being asked "what software refills a
     * cancelled appointment". Each question is the phrasing somebody would
     * actually type, and each answer's first sentence is the whole answer — the
     * rest is detail an engine can drop without making it wrong.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function home(): array
    {
        $product = (string) config('product.name');

        return [
            [
                'question' => 'What does '.$product.' do?',
                'answer' => $product.' is booking software for small appointment businesses. Customers '
                    .'book on your own link, a deposit is held on the card at booking, and when '
                    .'somebody cancels the slot is texted to your waitlist automatically — the first '
                    .'person to reply gets it.',
            ],
            [
                'question' => 'How does the waitlist fill a cancelled appointment?',
                'answer' => 'The moment an appointment is cancelled, '.$this->figures->offerBatch()
                    .' people on your waitlist who want that kind of appointment get a text with a '
                    .'link to claim it. They have '.$this->figures->offerMinutes().' minutes, and the '
                    .'first one to tap it takes the slot. You do not write the message, choose who '
                    .'gets it, or ring anybody round.',
            ],
            [
                'question' => 'Do I need to take deposits to use it?',
                'answer' => 'No. Deposits are per service, and setting one to zero means that service '
                    .'is paid on the day as it always was. If you do take them, they go to your own '
                    .'Stripe account rather than through us.',
            ],
            [
                'question' => 'What does it cost?',
                'answer' => $this->figures->monthlyBare().' a month, or '.$this->figures->yearlyBare()
                    .' a year. One price with everything in it — there are no tiers to grow into, and '
                    .'your customers are never charged a booking fee.',
            ],
            [
                'question' => 'Is there a free trial?',
                'answer' => 'Yes — '.$this->figures->trialDays().' days, and it takes no card to start. '
                    .'At the end of it you either pay or you do not; nothing is charged automatically '
                    .'because there is nothing to charge.',
            ],
            [
                'question' => 'What kinds of business is it for?',
                'answer' => 'Small businesses that book appointments and lose money when somebody does '
                    .'not turn up: dog groomers, barbers, beauty and nail salons, and the like. It is '
                    .'set up out of the box for dog grooming, with a price list and the questions you '
                    .'would ask about a dog already in it.',
            ],
            [
                'question' => 'Can my customers book without ringing me?',
                'answer' => 'Yes. You get a booking page on your own link that shows only the times you '
                    .'are genuinely free, and you can share it as a link or as a QR code. You can also '
                    .'set it so a booking is a request you confirm rather than a slot that is taken '
                    .'the moment somebody picks it.',
            ],
            [
                'question' => 'Do I have to move my existing customers over by hand?',
                'answer' => 'No. Customers and past appointments come in from a CSV file, and the import '
                    .'shows you exactly what it is about to create before it creates anything.',
            ],
        ];
    }

    /**
     * The pricing page's questions.
     *
     * These were written on the page itself and are unchanged in wording. They
     * moved here so that the same five are also published as structured data,
     * which is the whole reason this class exists.
     *
     * @return list<array{question: string, answer: string}>
     */
    public function pricing(): array
    {
        $product = (string) config('product.name');

        return [
            [
                'question' => 'What is the difference between monthly and yearly?',
                'answer' => 'Yearly is '.$this->figures->yearlyBare().', which works out at about '
                    .$this->figures->yearlyPerMonthBare().' a month. Monthly is '
                    .$this->figures->monthlyBare().' and you can stop whenever you like. Nothing else '
                    .'changes between them.',
            ],
            [
                'question' => 'What happens after the '.$this->figures->smsIncluded().' included texts?',
                'answer' => 'Top up another '.$this->figures->smsTopupSize().' for '
                    .$this->figures->smsTopupBare().'. Top-ups roll over rather than expiring at the '
                    .'end of the month. There is a ceiling of '.$this->figures->smsCeiling()
                    .' texts per account per month so a runaway loop can never hand you a bill you did '
                    .'not expect.',
            ],
            [
                'question' => 'Can I cancel during the trial?',
                'answer' => 'Yes, any time, and you are not charged. You give no card to start the trial, '
                    .'so there is nothing to cancel a payment on.',
            ],
            [
                'question' => 'Do my customers pay anything to '.$product.'?',
                'answer' => 'No. Deposits go to your own Stripe account, and '.$product.' only ever '
                    .'charges you the subscription. Stripe takes its own card processing fee from the '
                    .'deposit, the same as it would on any payment you take.',
            ],
            [
                'question' => 'What happens to my data if I stop paying?',
                'answer' => 'The account goes read-only rather than dark. Your customers\' booking page '
                    .'keeps working, you can still export everything, and nothing is deleted while the '
                    .'account exists. The <a href="'.route('marketing.terms').'">terms</a> set out how '
                    .'long that lasts.',
            ],
        ];
    }
}
