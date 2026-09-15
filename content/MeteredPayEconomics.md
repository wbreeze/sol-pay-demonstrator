---
title: What a dime has to do
slug: pay-per-view
created: 2026-09-04
revised: 2026-09-15
metered: true
status: published
lede: >
  At a penny, pay-per-view cannot come near what advertising earns. At about a
  dime it can, without tracking anyone, if one page view in five is paid for.
  A trial of sol-pay should claim exactly that. The trial should then measure
  what nobody has measured yet: how many readers pay at that price.
reading_time: 9
---

*By Douglas Lovell with Claude Opus 5 (Anthropic)*

The case for paying per article usually starts with a penny. The penny is not
really a price. It stands for a charge too small to notice: no decision, no
friction, nothing to weigh.

A penny cannot pay for a newsroom, as the arithmetic below shows. The better
question is how low a price has to be to feel like nothing — *sure, I'll toss
you a dime* — and whether enough readers pay at that price to come near what
advertising earns. Near is enough. A site that tracks nobody gives its readers
something that advertising cannot, even though no revenue figure shows it. A
trial of sol-pay should be built around that question. The trial should also
claim no more than it can measure.

## What advertising earns on a page view

Ad revenue here is counted per *page view*, not per impression. A page carries
several ad slots. Mixing up the two units is where arguments like this one
usually go wrong.

| comparator | $ per page view |
| --- | --- |
| open-market programmatic, marginal news inventory | $0.0072 |
| blended, including direct-sold, US metro daily | $0.0214 |
| premium direct-sold | ~$0.04 |

Sources: Lenfest Institute unit-economics study (Oct 2019) — $21.44 total RPM
and $7.16 programmatic RPM for a US metro daily, with an industry range of
$20–25 total and $6–10 "at the margins"; Operative/STAQ benchmarks via Digiday
(Feb 2023) — open-marketplace CPM $1.21 against programmatic-guaranteed $10.00,
an 8× spread.

**The data is old.** The best public news-specific figures are from 2019 and
January 2023. As of September 2026, nothing credible for 2025–26 has turned
up. A publisher running a trial should replace these figures with its own.

## Almost no visitor arrives unknown

A low price is usually defended like this. Most visitors never subscribe. The
publisher knows nothing about them. Their page views sell at the open-market
rate, the lowest in the table, so a small price beats that rate.

The defence confuses two kinds of knowing. "Open market" describes how an ad
slot is sold: an auction that any buyer can bid in. The phrase says nothing
about whether the reader is known. Each bidder prices the slot on whatever
profile the bidder holds, and the publisher never sees that profile.

Few visitors arrive without one, and the share who do may be heading toward
zero. Bot defences press from the other side. One of us refuses tracking. He
is regularly asked to prove he is human. That is a subject for another piece.

Two companies do most of the knowing. They put what they know to opposite uses.

**Google's tracking makes a publisher's ad slots pay better.** Google's
tracking feeds the auctions that sell those slots. Chrome kept third-party
cookies in 2025. Google has since shut down Privacy Sandbox, the replacement it
had been building.

**Meta's tracking makes Meta's own slots pay better.** Meta no longer sells ads
on other publishers' web pages. What Meta's code learns on a news site raises
the price of ads on Facebook and Instagram. Publishers carry Meta's code anyway,
to measure and target the campaigns they buy there for traffic and
subscribers.

A publisher carrying these trackers does not hold the profiles. The publisher
helps to produce them. Every page view feeds a profile that the tracking
company sells elsewhere, including to the sites competing for the same
reader's time.

It would be naive to think those profiles are harmless. In December 2024 the US
Federal Trade Commission charged a data broker, Mobilewalla, with collecting
more than 500 million advertising identifiers paired with precise locations,
some of them gathered from the auctions that sell ad slots. According to the
FTC, Mobilewalla built audiences of women who had visited pregnancy centres.
Mobilewalla also analysed the people who attended protests after George
Floyd's death. The harm deserves its own piece. This piece rests on a narrower
point: a site paid directly has no reason to feed a profile at all.

A payment through sol-pay does leave a record of its own. Paying writes one
permanent public line saying that a wallet paid the site. The line records how
much the wallet spent there. The line does not list what the wallet read.
[What this site holds](/privacy) covers the rest.

So the price has to meet the blended figure. Readers who will never subscribe
are about 99 of every 100 visitors. Their page views are priced on profiles
like everyone else's. Nothing in the sources says those page views earn only
the open-market rate.

## What tracking is worth

A site that stops tracking gives something up. The question is how much.

The tempting claim is that behavioural targeting is worth almost nothing to
publishers — Marotta, Abhishek & Acquisti (WEIS 2019) found a **4% premium,
$0.00008 per impression**. Do not build on it. It is one publisher's data from
one week in May 2016. Its headline 4% does not match the paper's own averages:
the revenue an impression earns with a cookie and without one differs there by
9.2%. And the weight of later evidence is against it:

- Google RCT (2019, top-500 GAM publishers): −52% average, **news −62%**.
  Partial equilibrium, so overstated.
- Johnson, Shriver & Du (*Marketing Science*, 2020): opt-out impressions worth
  ~52% less. Same bias direction.
- Skiera et al. on Apple ATT: trackable ads priced **51% higher**, yet actual
  post-ATT revenue fell only **15–23%** — the cross-sectional premium is about
  3× the market-level effect, which is the reconciliation.
- **Gu, Johnson & Kobayashi (PNAS 2026)** — the largest and best identified,
  200M+ impressions across 5,000+ publishers: removing third-party cookies cut
  publisher revenue **29.1%**; Privacy Sandbox recovered 4.2% of the loss.
  Chrome has since kept third-party cookies. Google has shut Privacy Sandbox
  down. The cut the study measured is one that Chrome chose not to make.

**Honest number: tracking is worth roughly 15–30% of publisher programmatic
revenue.** Not 4%, not 52%. A privacy argument that claims publishers lose
nothing by giving up tracking is making a claim the evidence does not support.

So what does a site give up when it stops tracking? Take it in steps.

1. An average page view earns **$0.0214** from advertising — the blended
   figure.
2. Of that, **$0.0072** comes from programmatic ads, sold at auction.
3. Tracking is worth 15% to 30% of programmatic revenue. A site that stops
   tracking loses that share of the $0.0072: between **$0.0011** and
   **$0.0022** per page view.
4. The studies above measured programmatic ads only. This adjustment leaves the
   rest of the blended figure alone.
5. So a page view on a site that does not track earns about **$0.0192 to
   $0.0203** from advertising: $0.0214, less the loss.

| per page view | with tracking | without tracking |
| --- | --- | --- |
| programmatic | $0.0072 | $0.0050 – $0.0061 |
| everything else | $0.0142 | $0.0142 |
| blended | $0.0214 | $0.0192 – $0.0203 |

Nothing here changes the price of an article. Losing tracking lowers the ad
revenue that a paid page view has to replace. The lower figure is the one a
price has to meet.

## The price and the share

Advertising earns on almost every page view. A price earns only on the page
views someone pays for. Call the price per article *p*, the ad revenue per
page view *A*, and the share of page views paid for *s*. To match the ad
revenue, the share has to be `s = A / p`. Against ad revenue without tracking,
$0.0192 to $0.0203:

| price per article | page views paid for |
| --- | --- |
| 1¢ | 192% – 203%, impossible |
| 5¢ | 38% – 41% |
| 10¢ | 19% – 20% |
| 15¢ | 13% – 14% |
| 25¢ | 8% |

The penny is out of reach at any share. The dime needs one page view in five.

A price also turns away readers who will not pay at all. With a traffic loss
*d*, the share becomes `s = A / (p·(1−d))`. Chiou & Tucker measured a 51% drop
in visits when Gannett put up paywalls in 2010. A dime with a loss that large
needs about 40% of the remaining page views paid for. A trial has to measure
the loss as well as the share.

Now the observed rates. The median publisher converts **0.6%** of visitors to
subscribers. The top quartile converts 1.4% (INMA via Press Gazette, Jul 2024).
Piano's 2024 benchmarks put known users at about **1%** of visitors, with 66%
one-off. Reuters Institute DNR 2025: **18% of adults across 20 richer
countries** pay for online news at all, 20% in the US. Subscription levels "now
look to have hit a ceiling."

One page view in five is more than 30 times the median subscription rate. The
two rates count different things — page views against visitors — so the
comparison is rough. Per-view payment asks for a far smaller commitment than a
subscription, and should convert better for that reason. Whether it converts
thirty times better is the question a trial answers.

## Where the market priced it

Every venture that tried per-article payment priced at a dime or more. Blendle
charged 19–39¢ for newspaper pieces. Cornwall Reports charged 20p, the
Maidenhead Advertiser 40p for a day pass, The New European 10p. Those prices
sit where the table above says a price has to sit.

The convergence is evidence *for* per-view payment as a model. It is also
evidence that a workable price starts at about a dime.

## Why a dime might reach one view in five

Blendle is the load-bearing precedent and its failure is usually
mis-remembered as payment friction. It was not. Klöpping's own numbers:
per-article buyers read **7 minutes a day; subscribers read 22.** Over five
years micropayments paid publishers €8m in total; for NRC Handelsblad a year of
Blendle equalled about **400 self-sold subscriptions** against a base of
241,000. Cafeyn's CEO, on shutting it down in 2023: "all-access bundles are
what paying news consumers expect — not small, individual purchases."

**The problem was the per-article decision, not the payment.** A reader asked to
choose, twenty times a day, whether this piece is worth 29¢ mostly answers no,
and stops being a habitual reader.

This is where sol-pay's architecture is genuinely different from Blendle's, and
it is worth saying plainly because it is the strongest structural argument
available. sol-pay does not ask for a decision per article. The reader
authorizes a limit **once**, and then reads with no interaction at all — the
`meter_and_settle` path never touches the wallet. Behaviourally that is much
closer to a subscription than to a newsstand, which is precisely the gap
Blendle's 7-versus-22 minutes measures.

What sol-pay cannot fix is that this remains unproven. **Google Contributor ran
per-page-view payment with the ad auction, the identity layer and the payment
rails all in-house, and abandoned it twice** (shut Jan 2017, relaunched Jun
2017, quietly dead). That is the single most damaging precedent and it should be
answered rather than ignored — the answer being that Contributor asked readers
to buy *out of* advertising on sites that kept running it, which is a different
and worse proposition than a site that does not track at all.

The one-time authorization is what a trial of sol-pay puts to the test. A
reader who agrees to a limit once, and then reads without deciding again, is
the reader who might pay for one page view in five.

## Where the money goes

**Half of what advertisers spend never reaches the publisher.** ISBA/PwC's two
Programmatic Supply Chain Transparency studies (data Jan–Mar 2020 and Sep–Oct
2022) both found that **51% of advertiser spend reaches the publisher**.
Between the two studies the unattributable "unknown delta" fell from ~15% to
3%. Transparency improved. The take rate did not move at all.

So a dime paid directly corresponds to about 20¢ of advertiser spend. The
figures above are already what the publisher nets, so this does not improve
the comparison. It does say where the money goes. A reader who pays directly
pays the site, with no auction in between.

## How to position the trial

**Claim this:** at about a dime an article, with one page view in five paid
for, direct payment comes near what a news site earns from advertising. The
site tracks nobody to earn it. A one-time authorization is what makes that
share plausible, where every per-article attempt fell short.

**Do not claim** that pay-per-view equals or beats targeted advertising. Do not
claim that a small price beats what
non-subscribers are worth, either. Those readers arrive profiled, and their
page views are priced like everyone else's.

**Measure** the three numbers the claim stands or falls on:

- the share of page views paid for, at the trial's price;
- the readers the price turns away, the *d* above;
- how long paying readers read, against Blendle's 7 minutes and a
  subscriber's 22.

The price and the ad figures are sourced. The share is an assumption, stated as
one. A publisher can check the ad figures against its own books in an
afternoon. The share is what the trial is for.

`page_price` is a per-site parameter in sol-pay, and nothing in the program
constrains it. A trial can set a dime, or run two sites at two prices. This
demonstrator charges 0.01 DEMO, a figure chosen so a visitor reaches the
collection threshold in ten views. That figure is not a recommendation.

## Sources

[Lenfest unit economics](https://www.lenfestinstitute.org/solutions-resources/one-subscriber-or-48000-page-views-why-journalists-should-know-the-unit-economics-of-digital-news/) ·
[Digiday / STAQ CPM benchmarks](https://digiday.com/media/the-programmatic-open-marketplace-is-faltering-but-publishers-see-a-bright-spot-in-private-programmatic-deals/) ·
[Marotta, Abhishek & Acquisti (WEIS 2019)](https://weis2019.econinfosec.org/wp-content/uploads/sites/6/2019/05/WEIS_2019_paper_38.pdf) ·
[Google cookie-disabling study](https://services.google.com/fh/files/misc/disabling_third-party_cookies_publisher_revenue.pdf) ·
[Skiera et al., FTC PrivacyCon](https://www.ftc.gov/system/files/ftc_gov/pdf/3-Skiera-Economic-Impact-of-Opt-in-versus-Opt-out-Requirements-for-Personal-Data-Usage.pdf) ·
[Gu, Johnson & Kobayashi (SSRN)](https://papers.ssrn.com/sol3/papers.cfm?abstract_id=5284526) ·
[ISBA/PwC I](https://www.isba.org.uk/system/files/media/documents/2020-12/executive-summary-programmatic-supply-chain-transparency-study.pdf) ·
[ISBA/PwC II](https://www.isba.org.uk/system/files/media/documents/2023-01/ISBA%20%20PwC%20programmatic%20supply%20chain%20study%20II%20(summary)-%2018%20January%202023.pdf) ·
[INMA conversion via Press Gazette](https://pressgazette.co.uk/media-audience-and-business-data/newsbrand-subscriber-conversion-rates-biggest-reader-funded-newsbrands-ranked/) ·
[Piano Subscription Benchmarks 2024](https://www.piano.io/marketing/content/subscription-performance-benchmarks-2024) ·
[Reuters Institute DNR 2025](https://reutersinstitute.politics.ox.ac.uk/digital-news-report/2025/dnr-executive-summary) ·
[Chiou & Tucker, paywalls](https://www.oxy.edu/sites/default/files/assets/Economics/Chiou/chiou_and_tucker_paywalls.pdf) ·
[Blendle pivot, Nieman Lab](https://www.niemanlab.org/2019/06/micropayments-for-news-pioneer-blendle-is-pivoting-from-micropayments/) ·
[Blendle exit, Nieman Lab](https://www.niemanlab.org/2023/08/the-poster-child-for-micropayments-for-news-is-getting-out-of-the-micropayments-business/) ·
[UK micropayments, Press Gazette](https://pressgazette.co.uk/paywalls/micropayments-for-news/) ·
[Chrome keeps third-party cookies, Didomi](https://www.didomi.io/blog/google-chrome-third-party-cookies-april-2025) ·
[Privacy Sandbox shut down, Adweek](https://www.adweek.com/media/googles-privacy-sandbox-is-officially-dead/) ·
[Meta ends web supply in Audience Network, AdExchanger](https://www.adexchanger.com/platforms/facebook-is-killing-off-its-web-supply-in-audience-network-and-dont-be-surprised-if-it-all-shuts-down/) ·
[Publishers buying Facebook traffic, eMarketer](https://www.emarketer.com/content/how-publishers-can-profit-from-buying-facebook-traffic) ·
[FTC action against Mobilewalla](https://www.ftc.gov/news-events/news/press-releases/2024/12/ftc-takes-action-against-mobilewalla-collecting-selling-sensitive-location-data)
