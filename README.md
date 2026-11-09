# Skydropx OpenCart Extension

The **Skydropx OpenCart Extension** connects an OpenCart 4 store to **Skydropx**. Once it is installed, Skydropx can read the store's orders, write fulfillment and tracking back to them, receive order webhooks, and show live Skydropx rates at checkout. OpenCart's native API cannot list orders or quote shipping, so Skydropx talks only to the endpoints this extension adds.

- **Contract version:** v1.1 (Skydropx OpenCart extension contract, `OPENCART.md` §12)
- **Extension version:** 1.0.0
- **OpenCart:** 4.0.2.x and 4.1.x (PHP 8.0+ / 8.1+)

## Features

- **Order sync**: paginated order list and single-order lookup. Each order includes the shipping address, products with weights and dimensions converted to kg/cm, payment status, and fulfillment status.
- **Fulfillment write-back**: Skydropx records each label (tracking number, carrier, URL and shipped quantities) and the order moves to the configured *Shipped* status once nothing is left to ship.
- **Tracking updates**: tracking events are added to the order history, and the order moves to the configured *Delivered* status when every shipment is delivered.
- **Order webhooks**: when an order history row is added (at checkout, by an admin, or by the extension), the order is POSTed to Skydropx with a signature.
- **Live checkout rates**: a `Skydropx` shipping method asks Skydropx for rates. Skydropx switches it on and off remotely (CARRIER_SERVICE_V2).
- **Admin page**: on/off switch, API key with copy and regenerate, origin address, status mapping, and a read-only view of what Skydropx configured.

## Installation

1. **Build the package** (or download a release):
   ```bash
   ./build.sh            # -> dist/skydropx.ocmod.zip
   ```
   The file must be called `skydropx.ocmod.zip`, because OpenCart 4 names the extension folder after the file.
2. **Upload it**: in the OpenCart admin, go to **Extensions > Installer**, upload `skydropx.ocmod.zip`, then click **Install** on the *Skydropx* row.
3. **Install the module**: go to **Extensions > Extensions**, choose **Modules**, find **Skydropx** and click **Install** (the green +). This step:
   - creates the tables `oc_skydropx_shipment`, `oc_skydropx_shipment_item` and `oc_skydropx_tracking_event`,
   - generates the API key (`random_bytes(32)`, 64 hex characters),
   - registers the order-history event,
   - registers the `skydropx` shipping method, which stays disabled until Skydropx enables it.
4. **Configure it**: click **Edit** on the Skydropx module.

Uninstalling the module (Extensions > Modules > Skydropx > Uninstall) removes the event, the shipping method, the settings and the three tables.

## Configuration

| Setting | Notes |
|---|---|
| Status | Turns the extension off. While it is off, every endpoint answers `403 EXTENSION_DISABLED`, checkout shows no Skydropx rates and no webhooks are sent. |
| API key | Paste this key in Skydropx when you connect the store. **Regenerate key** replaces it immediately, so you must update it in Skydropx afterwards. |
| Origin address | Business name, contact, e-mail, phone, address, city, postcode, country and state. Skydropx uses it for quotes and labels. It is empty until you fill it in. |
| Shipped status | Default *Shipped* (3). Applied when a label ships the last pending units. |
| Delivered status | Default *Complete* (5). Applied when every shipment of the order is delivered. |
| Cancelled statuses | Default 7, 8, 10, 11, 13, 14, 16. Orders in these statuses report `payment_status: cancelled` and are never listed. |
| Checkout rates / Quotation URL / Order webhooks / webhook URLs | Read-only. Skydropx sets them through the API. |

`paid` means the order status is in **Settings > Option > Processing Order Status** or **Complete Order Status**. `pending` means any other status that is not cancelled. Both come from the store settings; no status ids are hardcoded.

The shipping method page (Extensions > Shipping > Skydropx) only sets the **sort order**.

## What it exposes

Every endpoint is a catalog route, `{store}/index.php?route=extension/skydropx/api/<name>`. Each one is a controller `index()`, so the route separator never changes between versions. Every request must send the header `X-Skydropx-Api-Key: <key>`, which is compared with `hash_equals`. Responses are JSON. Amounts are in the store default currency, weights in kg, dimensions in cm, and dates in ISO 8601 UTC.

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
  - Each returned rate is shown as `skydropx.<service_code>`.
  - On any error the method returns no rates, and checkout keeps working with the other shipping methods.
- **Webhooks**: the extension listens on `catalog/model/checkout/order/addHistory/after` (4.0.2.x) or `catalog/model/checkout/order.addHistory/after` (4.1.x). Admin status changes go through the same catalog model, so they are caught too.
  - It POSTs `{"topic", "order"}` with `X-Skydropx-Topic: order.created` (first history row of the order) or `order.updated`.
  - It has a 3 s timeout. Failures are written to the OpenCart error log without secrets and never break the order flow.

## Development

```bash
./build.sh                                   # lint (php -l) + dist/skydropx.ocmod.zip
composer install && vendor/bin/phpcs         # PHPCompatibility (8.0+) and hygiene rules
```

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

The first boot runs the OpenCart CLI installer. The admin user is `admin`, with its password taken from `OC_ADMIN_PASSWORD` (default `admin`; change it). After that, install `dist/skydropx.ocmod.zip` as described above. The container can reach a mock Skydropx server on the host through `host.docker.internal`.

## Known limits

- **OpenCart 4.x only** (4.0.2.x and 4.1.x). OpenCart 3.0.x and 4.0.0.0–4.0.1.x are not supported.
- **Weights and dimensions are current product values.** OpenCart does not store weight or size on order lines, so if a product is edited after the sale, the order's weights change too. Option weight deltas are not included in order lines; checkout quotes do include them, because the cart computes them. Lines for products that do not require shipping are left out.
- **Non-MXN stores**: Skydropx rates are in MXN. If the store default currency is different, rates are converted with the store's own MXN exchange rate. If the store has no MXN currency, those rates are dropped. Order amounts are reported in the store default currency.
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
