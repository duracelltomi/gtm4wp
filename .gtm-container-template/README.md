# GTM4WP container template

The Google Tag Manager container export offered on gtm4wp.com, kept here so it is
version controlled next to the code whose data layer it reads.

Current file: **`gtm4wp-ga4-container-elements-20260810.json`** — 68 variables,
2 triggers, 3 tags. Built for the **GTM4WP 2.0** line (first shipped with 2.0.0).

Published at `https://gtm4wp.com/gtm-containers/<filename>`. The previous public
versions `gtm4wp-ga4-container-elements-20240129.json` (18 variables, 1 trigger,
1 tag) and `gtm4wp-ga4-container-elements-20200419.json` stay reachable so that
old inbound links do not break, but no gtm4wp.com page links them any more: pages
647, 681, 136 and 1093 were moved to the 20260810 file on 2026-08-11.

## What it contains

| Group | Count | Reads |
|---|---|---|
| `Ecommerce …` | 9 | `ecommerce.*` |
| `Order Data - …` | 25 | `orderData.*` — needs **Order data in data layer** |
| `Customer - …` | 25 | `customer*` — needs **Customer data in data layer** |
| Constants | 5 | see placeholders below |
| `User Data (Enhanced Conversions)` | 1 | `user_data` — needs **Customer data in data layer** |
| `Customer Type`, `New Customer` | 2 | `customer_type`, `new_customer` |

Triggers: **Event - Ecommerce Events** (regex over the ten GA4 ecommerce events) and
**Event - Purchase** (equals `purchase`).

Tags: **GA4 - Event - Ecommerce events** (`gaawe`), **Google Ads - Conversion -
Purchase** (`awct`), **Google Ads - User-provided Data - Purchase** (`awud`). Both
Google Ads tags fire on the purchase trigger; no tag sequencing.

Enhanced conversions run through the `awec` variable in **Code** mode, pointed at
`{{User Data (Enhanced Conversions)}}`. The plugin already emits Google's exact
`user_data` shape, so no per-field mapping is required.

## Placeholders that must be replaced after import

Five are constants, editable from the Variables list:

| Variable | Ships as | Replace with |
|---|---|---|
| `GA4 ID` | `G-XXXXXXXXXX` | GA4 measurement ID |
| `Google Ads Conversion ID` | `AW-XXXXXXXXX` | Account-wide Ads conversion ID |
| `Google Merchant Center ID` | `ADD_YOUR_GMC_ID` | Numeric GMC account ID |
| `Google Merchant Center Feed Country` | `ADD_YOUR_GMC_FEED_COUNTRY` | Feed territory code, e.g. `US` |
| `Google Merchant Center Feed Language` | `ADD_YOUR_GMC_FEED_LANG` | ISO 639-1, e.g. `en` |

Two are literals **inside** the `awct` tag, so nothing in the Variables list points at
them and they are the easy ones to miss:

| Field | Ships as | Note |
|---|---|---|
| `conversionLabel` | `REPLACE_WITH_YOUR_DATA` | Deliberately not a variable: a label belongs to one conversion action, so it is not reusable |
| `estimatedDeliveryDate` | `REPLACE_WITH_YOUR_DATA` | Not present in the data layer; needs a Custom JavaScript variable |

`estimatedDeliveryDate` is the one worth warning about loudest. A missing merchant ID
or label makes the conversion fail in a way Google Ads reports; an unreplaced delivery
date is a well-formed string that gets sent as real data. Google Ads' cart-data
diagnostics is the place to verify after publishing.

## Anonymization

The export carries the source container's account id, container id and public id in
every entity. Before committing a fresh export, replace them:

- `accountId` and `containerId` → `0`
- `publicId` and `tagIds[]` → `GTM-XXXXXXX`
- `path` and `tagManagerUrl` → `accounts/0/containers/0`
- `container.name` → a neutral name

Then grep the file for the old values to confirm none survived; they appear ~75 times
each, and the URL forms embed them separately from the bare fields.

## What has been verified, and how

Checked mechanically against `src/Modules/WooCommerce/` rather than assumed:

- every data layer path a variable reads exists in the plugin's output;
- no two variables point at the same path;
- every `{{…}}` reference in every tag resolves to a variable that exists;
- every tag fires on a trigger that exists;
- no duplicate entity names or ids.

Checked against Google's documentation:

- cart data requires `items.id`, `items.price` and `items.quantity`, and the plugin
  emits all three on purchase items, so `{{Ecommerce Items}}` satisfies it;
- `aw_merchant_id` / `aw_feed_country` / `aw_feed_language` appear **nowhere** in the
  plugin's output, which is why product reporting uses Custom Fields
  (`productReportingDataSource: JSON`) with constants rather than `DATA_LAYER`;
- new-customer reporting *can* use `DATA_LAYER`, because `new_customer` is top-level.

**Not verified: a real GTM import.** The JSON is structurally faithful to an export
GTM itself produced, but importing it into a throwaway workspace is still the check
that matters.
