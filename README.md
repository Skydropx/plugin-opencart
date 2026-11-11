# Skydropx OpenCart Extension

The **Skydropx OpenCart Extension** connects an OpenCart 4 store to **Skydropx**. Once it is installed, Skydropx can read the store's orders, write fulfillment and tracking back to them, receive order webhooks, and show live Skydropx rates at checkout. OpenCart's native API cannot list orders or quote shipping, so Skydropx talks only to the endpoints this extension adds.

- **Contract version:** v1.1 (Skydropx OpenCart extension contract, `OPENCART.md` §12)
- **Brands:** Skydropx and Solo Envíos, built from one template (see [Development](#development)). The extension version is set by the build.
- **OpenCart:** 4.0.2.x and 4.1.x (PHP 8.0+ / 8.1+)

## Features

- **Order sync**: paginated order list and single-order lookup. Each order includes the shipping address, products with weights and dimensions converted to kg/cm, payment status, and fulfillment status.
- **Fulfillment write-back**: Skydropx records each label (tracking number, carrier, URL and shipped quantities) and the order moves to the configured *Shipped* status once nothing is left to ship.
- **Tracking updates**: tracking events are added to the order history, and the order moves to the configured *Delivered* status when every shipment is delivered.
- **Order webhooks**: when an order history row is added (at checkout, by an admin, or by the extension), the order is POSTed to Skydropx with a signature.
- **Live checkout rates**: a shipping method named after the brand asks Skydropx for rates. Skydropx switches it on and off remotely (CARRIER_SERVICE_V2).
- **Admin page**: brand logo (when the brand has one), on/off switch, API key with copy and regenerate and a **Go to {brand}** button, origin address, status mapping, the build environment of the package, and a read-only view of what Skydropx configured.

## Installation

1. **Build the package** (or download a release):
   ```bash
   ./build.sh
   ```
   Answer the prompts: brand, version (semver, `v1.2.3` is accepted), environment (`local`, `sandbox` or `production`) and, for `local`, the ngrok URL. The package is written to:
   ```
   builds/<slug>-v<version>-<environment>/<code>.ocmod.zip
   # e.g. builds/solo-envios-v1.0.0-production/solo_envios.ocmod.zip
   ```
   The extension code (`<code>` below) is the brand slug with hyphens turned into underscores, because OpenCart rejects hyphens in extension codes: `skydropx`, `solo_envios`. It names the zip, the extension folder, the routes, the tables, the setting keys and the admin URL of that brand.

   **Do not rename the file.** OpenCart 4 takes the extension code from the file name (`basename($filename, '.ocmod.zip')`), so a renamed zip (for example `solo_envios (1).ocmod.zip`) installs into another folder and nothing works. Brand, version and environment go in the folder name, never in the file name.
2. **Upload it**: in the OpenCart admin, go to **Extensions > Installer**, upload `<code>.ocmod.zip`, then click **Install** on the brand's row (*Skydropx*, *Solo Envíos*).
3. **Install the module**: go to **Extensions > Extensions**, choose **Modules**, find the brand's module (**{brand}**) and click **Install** (the green +). This step:
   - creates the tables `oc_<code>_shipment`, `oc_<code>_shipment_item` and `oc_<code>_tracking_event`,
   - generates the API key (`random_bytes(32)`, 64 hex characters),
   - registers the order-history event (`<code>_order_history`) and, for brands other than Skydropx, the API alias event (`<code>_api_alias`, see [What it exposes](#what-it-exposes)),
   - registers the `<code>` shipping method, which stays disabled until Skydropx enables it.
4. **Configure it**: click **Edit** on the module.

Uninstalling the module (Extensions > Modules > {brand} > Uninstall) removes the events, the shipping method, the settings (`module_<code>_*`, `shipping_<code>_*`, `<code>_*`) and the three tables.

**Upgrading to a new version loses the connection.** OpenCart 4 has no in-place update: to replace the files you must first uninstall the module (which drops the tables, the settings and the API key) and then uninstall the extension in **Extensions > Installer** (the Installer refuses to uninstall it while the module is installed, and refuses to install a package whose `extension/<code>/` folder already exists). Installing the module again generates a new API key, which has to be pasted in Skydropx again.

## Configuration

| Setting | Notes |
|---|---|
| Status | Turns the extension off. While it is off, every endpoint answers `403 EXTENSION_DISABLED`, checkout shows no Skydropx rates and no webhooks are sent. |
| API key | Paste this key in Skydropx when you connect the store. **Regenerate key** replaces it immediately, so you must update it in Skydropx afterwards. The **Go to {brand}** button next to it opens `<app URL>/es-MX/merchant_stores` of the build's environment in a new tab (for example `https://sb-pro.skydropx.com/es-MX/merchant_stores` in a Skydropx sandbox build). |
| Origin address | Business name, contact, e-mail, phone, address, city, postcode, country and state. Skydropx uses it for quotes and labels. It is empty until you fill it in. |
| Shipped status | Default *Shipped* (3). Applied when a label ships the last pending units. |
| Delivered status | Default *Complete* (5). Applied when every shipment of the order is delivered. |
| Cancelled statuses | Default 7, 8, 10, 11, 13, 14, 16. Orders in these statuses report `payment_status: cancelled` and are never listed. |
| Package | Read-only, in the Connection section. The environment and ecommerce URL the package was built with, for example `sandbox · https://sb-ecommerce-pro.skydropx.com`. Support can use it to spot a sandbox package in a production store. |
| Checkout rates / Quotation URL / Order webhooks / webhook URLs | Read-only. Skydropx sets them through the API. |

The module header shows the brand logo when the package includes one (`admin/view/image/logo.png`, copied from the brand's assets). Brands without a logo show no image.

`paid` means the order status is in **Settings > Option > Processing Order Status** or **Complete Order Status**. `pending` means any other status that is not cancelled. Both come from the store settings; no status ids are hardcoded.

The shipping method page (Extensions > Shipping > {brand}) only sets the **sort order**.

## What it exposes

Every endpoint is a catalog controller `index()` (so the route separator never changes between versions), reachable at two routes:

- **Contract route**, `{store}/index.php?route=extension/skydropx/api/<name>`: the one the Skydropx backend calls for every brand. Like the literal `woocommerce_api_skydropx-*` endpoints of `plugin-woocommerce`, it does not change per brand. For brands other than Skydropx, the extension registers a `before` event on `catalog/controller/extension/skydropx/api/*` that rewrites the route to its own `extension/<code>/api/<name>`. It does nothing when the Skydropx extension itself is installed in the store (see [Known limits](#known-limits)).
- **Own route**, `{store}/index.php?route=extension/<code>/api/<name>`: also works directly, so nothing has to change in the extension if the backend ever builds the route per brand.

Every request must send the header `X-Skydropx-Api-Key: <key>`, which is compared with `hash_equals`. Responses are JSON. Amounts are in the store default currency, weights in kg, dimensions in cm, and dates in ISO 8601 UTC.

| Method | Route | Purpose |
|---|---|---|
| GET | `api/store` | Connect check: store name, URL, versions, currency, origin location, toggle states |
| GET | `api/orders` | `page`, `limit` (≤100, default 50), `payment_status` (`paid,pending`; empty = both; cancelled orders are never listed), `date_from` (default now−30 days). Only orders with a status and a shipping method are listed. |
| GET | `api/order&order_id=N` | One order, `{ "order": … }` |
| POST | `api/fulfillment` | `{order_id, tracking_number, carrier, tracking_url, items[{order_product_id, quantity}], notify}`. Idempotent on (order, tracking number). Quantities are clamped to what is left on each line. |
| POST | `api/tracking` | `{order_id, tracking_number, status, carrier, tracking_url}`. Idempotent on (order, tracking number, status). |
| POST | `api/quotation` | `{status, quotation_base_url}`: switches checkout rates on or off |
| POST | `api/webhooks` | `{status, url, update_url}`: switches order webhooks on or off |

Errors use `{"error":{"code","message"}}`:

- `401 INVALID_API_KEY`
- `403 EXTENSION_DISABLED`
- `404 ORDER_NOT_FOUND`
- `422 VALIDATION_ERROR`

### Outbound calls (store to Skydropx)

Both outbound calls are signed with `X-Skydropx-Signature: hex(hmac_sha256(raw_body, api_key))` and also send `X-Skydropx-Store-Url`.

- **Checkout quote**: `getQuote()` POSTs `{address_from, address_to, items[{id, quantity, weight, width, height, length}], total_price}` to the quotation URL.
  - It has a 5 s timeout, and the response is cached in the session for 5 minutes for identical requests.
  - Each returned rate is shown as `<code>.<service_code>`.
  - On any error the method returns no rates, and checkout keeps working with the other shipping methods.
- **Webhooks**: the extension listens on `catalog/model/checkout/order/addHistory/after` (4.0.2.x) or `catalog/model/checkout/order.addHistory/after` (4.1.x). Admin status changes go through the same catalog model, so they are caught too.
  - It POSTs `{"topic", "order"}` with `X-Skydropx-Topic: order.created` (first history row of the order) or `order.updated`.
  - It has a 3 s timeout. Failures are written to the OpenCart error log without secrets and never break the order flow.

## Development

```bash
./build.sh                                   # interactive build -> builds/<slug>-v<version>-<environment>/<code>.ocmod.zip
composer install && vendor/bin/phpcs         # PHPCompatibility (8.0+) and hygiene rules on plugin-source/
```

### Layout

```
plugin-opencart/
├── build.sh                              # interactive multi-brand builder
├── branding/<brand>/config.json          # brand values and URLs per environment
├── branding/<brand>/assets/images/logo.png   # optional: shown in the module header
├── plugin-source/                        # template with placeholders (install.json, admin/, catalog/)
└── builds/                               # output, one folder per build
```

Edit only `plugin-source/` and `branding/`. A brand is any folder in `branding/` that has a `config.json`; the build lists them in folder order (today `skydropx` and `solo-envios`).

### `branding/<brand>/config.json`

```json
{
  "plugin_name": "Skydropx",
  "plugin_slug": "skydropx",
  "plugin_description": "…",
  "plugin_author": "Skydropx",
  "plugin_author_uri": "https://pro.skydropx.com",
  "urls": {
    "local":      { "ecommerce": "NGROK_URL", "app": "http://skydropx.localhost.skydropx:3000" },
    "sandbox":    { "ecommerce": "https://sb-ecommerce-pro.skydropx.com", "app": "https://sb-pro.skydropx.com" },
    "production": { "ecommerce": "https://ecommerce.pro.skydropx.com", "app": "https://pro.skydropx.com" }
  }
}
```

| Key | Notes |
|---|---|
| `plugin_name` | Name the merchant sees (installer, admin, checkout rate name, logs). Must not contain `'`, `\` or `%`: it goes inside single-quoted PHP strings and is used as a `sprintf` format (`PLUGIN_NAME - %s`). |
| `plugin_slug` | `^[a-z0-9]+(-[a-z0-9]+)*$`. Used in the output folder name. The extension code is derived from it (`-` → `_`, `solo-envios` → `solo_envios`). |
| `plugin_description`, `plugin_author`, `plugin_author_uri` | `install.json` `description`, `author` and `link`. |
| `urls.<env>.ecommerce` | Shown in the read-only **Package** line. In `local` the value `NGROK_URL` is replaced by the ngrok URL entered at the prompt. |
| `urls.<env>.app` | Base of the **Go to {brand}** button (`<app>/es-MX/merchant_stores`). |

The keys are the same as in `plugin-woocommerce`, minus the ones OpenCart has no use for (`plugin_prefix`, `plugin_textdomain`, `plugin_uri`, `shipping_method_id`, `shipping_zone_name`) and `plugin_namespace`, which the build derives from the code. Every key above must be a non-empty string with no line breaks, and the URLs must start with `http://` or `https://` (except `NGROK_URL` in `local`). The build checks the config before copying anything and names the key and file that failed.

### Build script (`build.sh`)

Requires `jq`, `rsync` and `zip`. `php` is optional: without it the build warns and skips the `php -l` lint.

The flow and prompts are the ones of `plugin-woocommerce/build.sh`: brand, version, environment, ngrok URL (only for `local`, `https://<name>.ngrok-free.app` or `https://<name>.ngrok.io`) and, at the end, "Open builds directory? (y/n)". Then it:

1. Runs the white-label guard on `plugin-source/` (see [Placeholders](#placeholders)) and stops if it finds a forbidden `skydropx`.
2. Copies `plugin-source/` to `.tmp-build/` (without `.git`, `.gitignore`, `*.md`, `.DS_Store` and `tests`). A `trap` removes `.tmp-build/` on exit.
3. Replaces the placeholders in `*.php` and `*.twig` with `sed`, renames the files that have `PLUGIN_SLUG` in their name (`rename_template_files`, as in Woo), and rewrites `install.json` with `jq` (including `code`).
4. Copies `branding/<brand>/assets/images/logo.png`, if it exists, to `admin/view/image/logo.png`.
5. Runs `validate_build`:
   - no placeholder token is left in any text file or file name,
   - `php -l` passes on every `.php` file (when `php` is installed),
   - `install.json` is valid JSON, its `code` is the extension code and its `version` is the one entered,
   - `admin/` and `catalog/` exist.
6. Removes the previous zip and writes `builds/<slug>-v<version>-<environment>/<code>.ocmod.zip` with `install.json`, `admin/` and `catalog/` at the root, then prints a summary (brand, version, environment, ecommerce URL, path and size) and the reminder not to rename the file.

If the config is invalid, a validation fails or stdin runs out at the brand, version, environment or ngrok prompt, the build exits with a non-zero status and writes no package (a zip left at the same path by an earlier successful build is not removed). Running out of input at "Open builds directory? (y/n)" counts as `n`: the package is already built and the script exits 0.

The prompts read stdin, so the build can run without interaction, for example Skydropx, `1.0.0`, `sandbox`, don't open the folder:

```bash
printf '1\n1.0.0\n2\nn\n' | ./build.sh
```

### Placeholders

Placeholders in `plugin-source/` use the same names as in `plugin-woocommerce`:

| Placeholder | Value | Used in |
|---|---|---|
| `PLUGIN_NAMESPACE` | derived from the code as OpenCart does (`str_replace('_', '', ucwords($code, '_'))`): `Skydropx`, `SoloEnvios` | Namespaces (`Extension\PLUGIN_NAMESPACE\…`), class names (`class PLUGIN_NAMESPACE`) and `@package` |
| `PLUGIN_NAME` | `plugin_name` | Branded texts in the 6 language files (admin and catalog, `en-gb` and `es-mx`), checkout rate name (`text_rate_name` = `PLUGIN_NAME - %s`), `log->write` prefix, event description, default "Shipped with …" comment, `EXTENSION_DISABLED` message, `install.json` `name`, and brand mentions in comments and docblocks |
| `PLUGIN_SLUG` | extension code: `plugin_slug` with `-` → `_` (`skydropx`, `solo_envios`) | File names (`PLUGIN_SLUG.php`, `PLUGIN_SLUG.twig`), routes `extension/PLUGIN_SLUG/…`, `install.json` `code`, settings (`PLUGIN_SLUG_*`, `module_PLUGIN_SLUG_*`, `shipping_PLUGIN_SLUG_*`), tables `PLUGIN_SLUG_shipment*`, events (`PLUGIN_SLUG_order_history`, `PLUGIN_SLUG_api_alias`), rate code `PLUGIN_SLUG.<service>`, session key, `model_extension_PLUGIN_SLUG_*`, variables (`$PLUGIN_SLUG`), language keys (`text_PLUGIN_SLUG`), twig names and User-Agent `PLUGIN_SLUG-opencart/` + version |
| `PLUGIN_DESCRIPTION` | `plugin_description` | `install.json` `description` |
| `PLUGIN_AUTHOR` | `plugin_author` | `install.json` `author` |
| `PLUGIN_AUTHOR_URI` | `plugin_author_uri` | `install.json` `link` |
| `PLUGIN_VERSION` | version prompt | `install.json` `version`, `EXTENSION_VERSION` (catalog model) |
| `PLUGIN_ENVIRONMENT` | environment prompt (`local`, `sandbox`, `production`) | `BUILD_ENVIRONMENT` (admin model) |
| `ECOMMERCE_URL` | `urls.<env>.ecommerce`, or the ngrok URL | `ECOMMERCE_BASE` (admin model) |
| `APP_URL` | `urls.<env>.app` | `APP_BASE` (admin model) |

Rules:

- Replacement is case-sensitive and goes from the longest key to the shortest (`PLUGIN_AUTHOR_URI` before `PLUGIN_AUTHOR`, `PLUGIN_NAMESPACE` before `PLUGIN_NAME`).
- Identifiers contain placeholders on purpose (`class PLUGIN_NAMESPACE`, `$PLUGIN_SLUG`), and the template is still valid PHP. Anything that must not be replaced must not look like a placeholder: the constant is `APP_BASE`, not `APP_URL`.
- `install.json` is written with `jq`, never with `sed`, so quotes or accents in the description cannot break the JSON.
- **White-label guard**: the only literal forms of `skydropx` (any case) allowed in `plugin-source/` are the `X-Skydropx-*` / `HTTP_X_SKYDROPX_*` headers (in code and API messages, never in language texts) and the `CONTRACT_CODE = 'skydropx'` constant that builds the alias route and trigger. `build.sh` greps `plugin-source/` for anything else, and also rejects file or directory names containing `skydropx` (they must use `PLUGIN_SLUG`), and stops the build if it finds either.

To run phpcs without PHP on the host:

```bash
docker run --rm -u "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer -v "$PWD":/app -w /app composer:2 \
  sh -c 'composer install -q && vendor/bin/phpcs'
```

### Local store (Docker)

`docker/` builds OpenCart 4.1.0.4 on `php:8.2-apache` with MySQL 8.0. The compose project is `opencart-e2e`, and both ports are bound to localhost.

```bash
docker compose -f docker/docker-compose.yml up -d --build
# store http://localhost:8091/   admin http://localhost:8091/admin/   MySQL localhost:3390 (root/opencart)
```

The first boot runs the OpenCart CLI installer. The admin user is `admin`, with its password taken from `OC_ADMIN_PASSWORD` (default `admin`; change it). After that, install `builds/<slug>-v<version>-<environment>/<code>.ocmod.zip` as described above. The container can reach a mock Skydropx server on the host through `host.docker.internal`.

## Known limits

- **OpenCart 4.x only** (4.0.2.x and 4.1.x). OpenCart 3.0.x and 4.0.0.0–4.0.1.x are not supported.
- **Weights and dimensions are current product values.** OpenCart does not store weight or size on order lines, so if a product is edited after the sale, the order's weights change too. Option weight deltas are not included in order lines; checkout quotes do include them, because the cart computes them. Lines for products that do not require shipping are left out.
- **Non-MXN stores**: Skydropx rates are in MXN. If the store default currency is different, rates are converted with the store's own MXN exchange rate. If the store has no MXN currency, those rates are dropped. Order amounts are reported in the store default currency.
- **One brand per store**: installing two brands (for example Skydropx and Solo Envíos) in the same OpenCart is not supported. When the Skydropx extension is present, the other brand's alias does nothing, so the contract route reaches only Skydropx.
- **Multi-store**: settings live on the default store and there is one connection per installation.
  - API calls only see orders of the store whose URL was called.
  - Webhooks are sent only for the store that enabled them.
  - Origin address and API key are shared across all stores.
- **Best-effort webhooks**: there is a single attempt with a 3 s timeout and no retry queue. Skydropx's manual sync (`api/orders`) is the safety net. Changes that bypass `addHistory` (direct DB edits, some third-party admin modules) do not fire webhooks.
- **"First seen" heuristic**: an order is sent as `order.created` when it has exactly one history row. If webhooks are enabled after an order already exists, that order's first webhook is `order.updated`.
- **Checkout themes and one-page checkouts** that do not use OpenCart's `getQuote()` loop will not show Skydropx rates.
- **Maintenance mode** blocks the API like the rest of the storefront.
- **Hosting**: the store must be able to make outbound HTTPS calls (cURL) for quotes and webhooks.

## License

Proprietary, © Skydropx.
