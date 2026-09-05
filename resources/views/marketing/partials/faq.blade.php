{{--
    The questions, rendered from `App\Support\MarketingFaq`.

    One partial for every page that has an FAQ, and it takes the list rather than
    holding it, because the same list is also serialised as `FAQPage` JSON-LD by
    `marketing/partials/schema.blade.php`. Google's structured-data policy
    requires the markup to match the visible text; the only way to guarantee that
    is for there to be one copy of the words. Prose typed into this file would be
    a second copy nothing keeps in step.

    **Written for a person and an answer engine at once.** The question is an
    `<h3>` and the answer is the paragraph immediately after it, with the whole
    answer in the first sentence — which is what a person scanning wants and what
    an engine extracts. No `<details>`: content behind a closed disclosure is
    content a crawler may reasonably treat as hidden, and this is the part of the
    page we most want read.

    `$faq` — list<array{question: string, answer: string}>
    `$heading` — the section's own h2. Optional; "Questions" by default.

    The answer is rendered unescaped, and that is safe here and nowhere else:
    the strings come from a PHP class in this repository and never from a
    request. An answer may contain a link and should contain nothing else.
--}}
@if (($faq ?? []) !== [])
    <section class="faq" aria-labelledby="faq-heading">
        <div class="faq-inner">
            <h2 id="faq-heading">{{ $heading ?? 'Questions' }}</h2>
            @foreach ($faq as $item)
                <div class="q">
                    <h3 class="question">{{ $item['question'] }}</h3>
                    <p class="answer">{!! $item['answer'] !!}</p>
                </div>
            @endforeach
        </div>
    </section>
@endif
