# Order Data Extension contract

The default Salesforce Marketing Cloud Data Extension External Key is `PS_ORDERS`.

## Primary key

`OrderKey` must be the primary key and uses the stable connector format:

`ps_{shop_id}_{order_id}`

This key does not change if the order status or payment state changes.

## Fields

| Field | Suggested type | Notes |
| --- | --- | --- |
| OrderKey | Text(191), Primary Key | Stable connector order identifier |
| OrderId | Number | PrestaShop order ID |
| ShopId | Number | PrestaShop shop ID |
| CartId | Number | Source cart ID; used for abandonment suppression/attribution |
| CustomerId | Number | PrestaShop customer ID |
| ContactKey | Text(191) | `ps_{shop_id}_{customer_id}` when available |
| Reference | Text(64) | PrestaShop order reference |
| CurrencyId | Number | PrestaShop currency ID |
| Currency | Text(8) | ISO currency code when available |
| TotalPaid | Decimal | Order total including tax |
| TotalProducts | Decimal | Products total including tax where available |
| TotalShipping | Decimal | Shipping total including tax where available |
| TotalDiscounts | Decimal | Discounts including tax where available |
| Payment | Text(191) | Human-readable payment method |
| Module | Text(191) | Payment module technical name |
| StateId | Number | Latest known PrestaShop order state ID |
| Paid | Boolean | Latest state declares the order paid |
| Valid | Boolean | PrestaShop order `valid` flag |
| EventType | Text(32) | `ORDER_CREATED`, `ORDER_UPDATED`, `ORDER_PAID` or `ORDER_CANCELLED` |
| DateAdd | Date | PrestaShop creation timestamp |
| DateUpd | Date | PrestaShop update timestamp |

## Event model

`actionValidateOrder` creates `ORDER_CREATED`.

`actionOrderStatusPostUpdate` updates the same row using one of:

- `ORDER_PAID` when the new `OrderState` has `paid = true`;
- `ORDER_CANCELLED` when it matches PrestaShop's configured canceled state;
- `ORDER_UPDATED` for other status transitions.

The local `sfmc_order_sync` ledger stores `id_shop + id_order + id_cart` before Salesforce delivery. This means future abandoned-cart processing can fail closed when a source cart has already produced an order, even if Marketing Cloud is temporarily unavailable.

## Salesforce permissions

The Installed Package needs `data_extensions_write` for row upserts.
