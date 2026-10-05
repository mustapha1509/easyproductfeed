# EasyProductFeed — Facebook & Instagram Product Catalogue for PrestaShop 8.x / 9.x

Author: Nasraoui Mustapha

Exports your products as a feed for **Meta Commerce Manager**, the catalogue behind Product Ads,
Dynamic Ads, Instagram product tagging and the Facebook Shop tab.

## Install

1. Upload `easyproductfeed.zip` in Back Office > Modules > Module Manager > Upload a module.
2. Open the module > **Feed profiles** > create a *Primary* profile > **Generate now**.
3. Copy the profile's **Feed URL** into Commerce Manager (Catalog > Data sources > Data feed > Scheduled feed).
4. Add the **cron URL** shown on the module page to your server's crontab (nightly is fine).

The module page also contains the full step-by-step guide.

## How multilingual / multi-country works

Meta's own model is one *primary* feed plus *override* feeds, all feeding the **same catalogue**:

| Profile type | Columns | Use it for |
|---|---|---|
| Primary | full product data | your main language / currency |
| Language override | `id, override, title, description` | translated titles and descriptions |
| Country override | `id, override, price, sale_price, availability, link` | country-specific prices and currencies |

Override feeds apply exactly the same skip rules as the primary feed, so their IDs always match.

## What is in this build

| Requested feature | Status |
|---|---|
| One catalogue per shop | Yes: profiles are per shop (multistore: select a shop first) |
| Multilanguage, per-country prices/currencies | Yes: language / country override profiles |
| Combinations as variants | Yes (option, off by default: see the EasyPixel note) |
| Product/image customisation | Yes: ID mode + prefix, brand, description source, image type, extra images, tax, stock quantity |
| Gender, age group, colour, size (+ material, pattern) | Yes: gender/age via category mapping; colour/size from attribute groups or features |
| Google product categories | Yes: manual mapping per category (ID or path), inherited by child categories |
| Category exclusion | Yes: replaces manual product filtering for "never export this" |
| Background generation | Yes: "Generate in background" button + cron endpoint, with per-profile locking |
| Cron automation | Yes |
| Manual generation | Yes |
| Errors | Partly: own generation report (skipped products, missing images/GTIN/...) and an optional "Check Meta errors" button (Graph API) |
| Product Sets, Instagram tagging, Shop tab, Dynamic Ads | Configured inside Meta (see guide). The module supplies the catalogue |

## Things to verify on your first real upload

I could not test against a live PrestaShop or a live Meta account, so please check:

1. **Override codes.** For localized profiles, the "Override value" must be a code Meta supports (ISO country code such as `TN`; language codes per Meta's "Localized catalog" docs).
2. **3-decimal currencies (TND, KWD...).** Prices follow PrestaShop's currency precision. If Meta rejects them, set *Price decimals = Always 2*.
3. **Image type.** `large_default` must exist in your theme and be at least 500x500 px.
4. **"Check Meta errors".** It reads the latest feed upload report through the Graph API. If Meta changes a field name, the button shows Meta's error message instead of failing silently.
5. **Background generation** relies on your host letting a PHP request outlive the caller. If the host kills long requests, use the cron job (it has the same limit) or lower the batch size.

## Compatibility with EasyPixel (Dynamic Ads)

Dynamic Ads only work if the pixel's `content_ids` equal the catalogue item IDs. EasyPixel currently sends the
**Product ID**, so keep *Item ID = Product ID* and *variants = No*. Exporting variants needs the pixel to report
variant IDs (or `content_type = product_group`), which EasyPixel does not do yet.

"Stop showing the ad after purchase" is handled by the pixel's Purchase event (EasyPixel), not by the feed.

## Security

Feed files are stored in `modules/easyproductfeed/feeds/` behind a deny-all `.htaccess` and are served only through a
token-protected controller. The token is generated at install. Keep the feed and cron URLs private.
(On nginx, `.htaccess` is ignored: add a `location ~ ^/modules/easyproductfeed/feeds/ { deny all; }` rule.)

## Tested

PHP syntax-linted on 8.3, and the generator and admin screens were exercised end to end against stubbed PrestaShop
classes with all notices/warnings promoted to errors (feed output, XML well-formedness, primary/override ID parity,
variants, price formats, input sanitising, validation). That validates the module's own logic, **not** the
PrestaShop API calls themselves. Test on staging first.
