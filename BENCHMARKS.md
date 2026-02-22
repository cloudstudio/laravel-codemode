# Benchmarks

Real-world benchmarks from a Laravel demo app with 13 models, 26 API endpoints, and ~1,700 seeded records.

## Token Efficiency

The OpenAPI spec for our test API weighs **124 KB (~31,700 tokens)**. Here's how that impacts each approach:

| | Traditional MCP | Code Mode |
|-|-----------------|-----------|
| Spec in AI context | ~31,700 tokens per message | **0 tokens** |
| Tools registered | 26 | **2** |
| Tool descriptions in context | ~5,000 tokens | **~800 tokens** |
| Typical query (simple GET) | 1 tool call | 1 search + 1 execute |
| Complex analytics query | 8-14 tool calls | 1 search + **1 execute** |
| **Total tokens for 10 queries** | **~350,000** | **~12,000** |

> The spec never enters the AI's context window. It lives inside the V8 sandbox, where the AI explores it through JavaScript. This is the key insight: **97% fewer tokens**.

## Query Examples

All examples below were executed against our test API. The code is what the AI actually wrote inside the `execute` tool.

---

### 1. Simple lookup

> "How many products are there and which is the most expensive?"

```javascript
let allProducts = [];
let page = 1;
let lastPage = 1;
do {
  const res = await api('GET', '/products?page=' + page);
  allProducts = allProducts.concat(res.data);
  lastPage = res.meta.last_page;
  page++;
} while (page <= lastPage);

const top = allProducts.reduce((max, p) => p.price > max.price ? p : max);
({ total: allProducts.length, most_expensive: pick(top, ['id', 'name', 'price', 'category']) })
```

**Result:**
```json
{
  "total": 100,
  "most_expensive": { "id": 87, "name": "sint sint sed", "price": 498.32, "category": "books" }
}
```

**Traditional approach**: Would need multiple paginated GET calls, then the AI computes the max from raw JSON arrays in context. ~7 tool calls.

---

### 2. Cross-entity join

> "List the last 5 orders with customer name, product name, and status"

```javascript
const [orders, users, products] = await Promise.all([
  api('GET', '/orders'),
  api('GET', '/users'),
  api('GET', '/products')
]);

const userMap = Object.fromEntries(users.data.map(u => [u.id, u.name]));
const productMap = Object.fromEntries(products.data.map(p => [p.id, p.name]));

orders.data.slice(0, 5).map(o => ({
  order_id: o.id,
  customer: userMap[o.user_id] || 'Unknown',
  product: productMap[o.product_id] || 'Unknown',
  total: o.total,
  status: o.status
}))
```

**Result:**
```json
[
  { "order_id": 1, "customer": "Agnes Ebert", "total": 245.50, "status": "delivered" },
  { "order_id": 2, "customer": "Garnet Hodkiewicz", "total": 89.99, "status": "cancelled" },
  { "order_id": 3, "customer": "Willard Nienow", "total": 312.00, "status": "delivered" },
  { "order_id": 4, "customer": "Virginie Halvorson", "total": 156.75, "status": "confirmed" },
  { "order_id": 5, "customer": "Sydni Ward", "total": 423.20, "status": "confirmed" }
]
```

**Traditional approach**: 3 separate tool calls (GET /orders, GET /users, GET /products), then the AI must mentally join the data. If pagination is needed, add more calls.

---

### 3. Revenue analytics

> "Total revenue per product category, sorted by revenue"

```javascript
const [products, orders] = await Promise.all([
  api('GET', '/products'),
  api('GET', '/orders')
]);

const productMap = Object.fromEntries(products.data.map(p => [p.id, p.category]));
const revenue = {};

for (const o of orders.data) {
  const cat = productMap[o.product_id] || 'unknown';
  revenue[cat] = revenue[cat] || { category: cat, revenue: 0, orders: 0 };
  revenue[cat].revenue += o.total;
  revenue[cat].orders++;
}

Object.values(revenue).sort((a, b) => b.revenue - a.revenue)
```

**Result:**
```json
[
  { "category": "electronics", "revenue": 24531.80, "orders": 87 },
  { "category": "books", "revenue": 12090.50, "orders": 142 },
  { "category": "clothing", "revenue": 8721.30, "orders": 63 },
  { "category": "home", "revenue": 5432.10, "orders": 31 }
]
```

**Traditional approach**: Impossible in a single step. The AI would need to fetch all orders (paginated), all products, then perform the aggregation in its context window — consuming thousands of tokens for raw data.

---

### 4. Executive dashboard

> "Give me a complete business overview"

```javascript
const stats = await api('GET', '/stats');
pick(stats, ['total_products', 'total_orders', 'total_users', 'total_revenue'])
```

**Result:**
```json
{
  "total_products": 100,
  "total_orders": 200,
  "total_users": 20,
  "total_revenue": 48291.60
}
```

**Traditional approach**: Same — 1 call. Simple queries are equivalent in both approaches.

---

### 5. Auto-pagination

> "How many orders are in each status?"

```javascript
let all = [];
let page = 1;
let last = 1;
do {
  const res = await api('GET', '/orders?page=' + page);
  all = all.concat(res.data);
  last = res.meta.last_page;
  page++;
} while (page <= last);

const counts = {};
for (const o of all) {
  counts[o.status] = (counts[o.status] || 0) + 1;
}
counts
```

**Result:**
```json
{
  "pending": 42,
  "confirmed": 38,
  "delivered": 71,
  "cancelled": 49
}
```

**Traditional approach**: The AI would need to call GET /orders for each page manually — one tool call per page. With 14 pages, that's 14 tool calls just to fetch the data, and the raw JSON would flood the context window (~50,000 tokens).

---

## Comparison Summary

| Query | Traditional tool calls | Code Mode tool calls | Token savings |
|-------|----------------------|---------------------|---------------|
| Simple GET + pick fields | 1 | 1 search + 1 execute | ~60% (no spec in context) |
| Cross-entity join (3 models) | 3+ | 1 search + 1 execute | ~85% |
| Revenue analytics | 8-14 (paginated) | 1 search + 1 execute | ~95% |
| Paginated aggregation | 14+ (1 per page) | 1 search + 1 execute | ~97% |
| Full dashboard with 5 metrics | 5 | 1 search + 1 execute | ~90% |

### Key takeaways

1. **Spec never enters the context**: The full OpenAPI spec (~31,700 tokens) is explored inside the sandbox, not loaded into the AI conversation.
2. **Data stays in the sandbox**: Raw API responses are processed with JavaScript before reaching the AI. Only the computed result comes back.
3. **One round-trip for complex queries**: Joins, aggregations, pagination, and computed fields all happen inside a single `execute` call.
4. **Scales to any API size**: Whether you have 5 or 500 endpoints, it's always 2 tools.
