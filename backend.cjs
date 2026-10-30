// PLEZZY Backend & Database API Service
const fs = require('fs');
const path = require('path');

const DATA_DIR = path.join(__dirname, 'data');
const ORDERS_FILE = path.join(DATA_DIR, 'orders.json');
const PRODUCTS_FILE = path.join(__dirname, 'assets', 'products.json');
const UPLOAD_DIR = path.join(__dirname, 'assets', 'products');
const CUSTOMERS_FILE = path.join(DATA_DIR, 'customers.json');

// Ensure data directory exists
if (!fs.existsSync(DATA_DIR)) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
}

// Ensure orders.json exists
if (!fs.existsSync(ORDERS_FILE)) {
  fs.writeFileSync(ORDERS_FILE, JSON.stringify([], null, 2), 'utf8');
}

function readProducts() {
  try {
    if (!fs.existsSync(PRODUCTS_FILE)) return [];
    const raw = fs.readFileSync(PRODUCTS_FILE, 'utf8');
    return JSON.parse(raw);
  } catch (err) {
    console.error('[Backend] Error reading products.json:', err.message);
    return [];
  }
}

function writeProducts(products) {
  try {
    const tempFile = PRODUCTS_FILE + '.tmp';
    fs.writeFileSync(tempFile, JSON.stringify(products, null, 2), 'utf8');
    fs.renameSync(tempFile, PRODUCTS_FILE);
    return true;
  } catch (err) {
    console.error('[Backend] Error writing products.json:', err.message);
    return false;
  }
}

function readOrders() {
  try {
    if (!fs.existsSync(ORDERS_FILE)) return [];
    const raw = fs.readFileSync(ORDERS_FILE, 'utf8');
    return JSON.parse(raw);
  } catch (err) {
    console.error('[Backend] Error reading orders.json:', err.message);
    return [];
  }
}

function writeOrders(orders) {
  try {
    const tempFile = ORDERS_FILE + '.tmp';
    fs.writeFileSync(tempFile, JSON.stringify(orders, null, 2), 'utf8');
    fs.renameSync(tempFile, ORDERS_FILE);
    updateCustomerDatabase(orders);
    return true;
  } catch (err) {
    console.error('[Backend] Error writing orders.json:', err.message);
    return false;
  }
}

function updateCustomerDatabase(orders) {
  try {
    const customerMap = new Map();
    orders.forEach(ord => {
      const cust = ord.customer || {};
      const key = (cust.email || cust.phone || cust.fullName || 'unknown').toLowerCase().trim();
      if (!customerMap.has(key)) {
        customerMap.set(key, {
          id: 'CUST-' + Math.abs(key.split('').reduce((a, b) => ((a << 5) - a) + b.charCodeAt(0), 0)).toString().slice(0, 6),
          fullName: cust.fullName || 'Anonymous',
          email: cust.email || '',
          phone: cust.phone || '',
          city: cust.city || ord.origin?.city || 'Unknown',
          state: cust.state || ord.origin?.region || '',
          country: cust.country || ord.origin?.country || 'India',
          totalOrders: 0,
          totalSpent: 0,
          currency: 'INR',
          firstOrderDate: ord.createdAt,
          lastOrderDate: ord.createdAt,
          addresses: new Set(),
          lastOrderNumber: ord.id
        });
      }
      const record = customerMap.get(key);
      record.totalOrders += 1;
      record.totalSpent += (ord.pricing?.total || 0);
      if (new Date(ord.createdAt) > new Date(record.lastOrderDate)) {
        record.lastOrderDate = ord.createdAt;
        record.lastOrderNumber = ord.id;
      }
      if (cust.address) {
        record.addresses.add(`${cust.address}, ${cust.city || ''} ${cust.pincode || ''}`.trim());
      }
    });

    const customers = Array.from(customerMap.values()).map(c => ({
      ...c,
      addresses: Array.from(c.addresses)
    }));

    fs.writeFileSync(CUSTOMERS_FILE, JSON.stringify(customers, null, 2), 'utf8');
  } catch (e) {
    console.error('[Backend] Error updating customers.json:', e.message);
  }
}

function parseDevice(userAgent = '') {
  const ua = userAgent.toLowerCase();
  let deviceType = 'Desktop';
  let os = 'Unknown OS';
  let browser = 'Unknown Browser';

  if (/mobile|android|iphone|ipad|ipod|blackberry|opera mini|iemobile/i.test(ua)) {
    deviceType = 'Mobile';
  } else if (/tablet|ipad/i.test(ua)) {
    deviceType = 'Tablet';
  }

  if (/iphone/i.test(ua)) os = 'iPhone iOS';
  else if (/ipad/i.test(ua)) os = 'iPad iOS';
  else if (/android/i.test(ua)) os = 'Android';
  else if (/macintosh|mac os x/i.test(ua)) os = 'macOS';
  else if (/windows nt/i.test(ua)) os = 'Windows';
  else if (/linux/i.test(ua)) os = 'Linux';

  if (/chrome|crios/i.test(ua) && !/edg/i.test(ua)) browser = 'Chrome';
  else if (/safari/i.test(ua) && !/chrome/i.test(ua)) browser = 'Safari';
  else if (/firefox|fxios/i.test(ua)) browser = 'Firefox';
  else if (/edg/i.test(ua)) browser = 'Edge';

  return `${deviceType} (${os} ${browser})`.trim();
}

function getClientIp(req) {
  const forwarded = req.headers['x-forwarded-for'];
  if (forwarded) {
    const first = forwarded.split(',')[0].trim();
    if (first) return first;
  }
  if (req.headers['cf-connecting-ip']) return req.headers['cf-connecting-ip'];
  if (req.headers['x-real-ip']) return req.headers['x-real-ip'];
  let remote = req.socket?.remoteAddress || '127.0.0.1';
  if (remote.startsWith('::ffff:')) remote = remote.replace('::ffff:', '');
  if (remote === '::1') remote = '127.0.0.1';
  return remote;
}

function isPrivateIp(ip) {
  if (!ip || ip === '127.0.0.1' || ip === 'localhost') return true;
  if (ip.startsWith('192.168.') || ip.startsWith('10.') || ip.startsWith('172.16.')) return true;
  return false;
}

async function resolveGeo(ip, clientHints = {}) {
  const fallback = {
    ip: ip || '127.0.0.1',
    city: clientHints.city || 'Mumbai',
    region: clientHints.state || 'Maharashtra',
    country: clientHints.country || 'India',
    countryCode: 'IN',
    timezone: clientHints.timezone || 'Asia/Kolkata',
    isp: 'Local Network'
  };

  if (isPrivateIp(ip)) {
    return fallback;
  }

  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 2000);
    const res = await fetch(`http://ip-api.com/json/${ip}?fields=status,message,country,countryCode,regionName,city,zip,lat,lon,timezone,isp`, {
      signal: controller.signal
    });
    clearTimeout(timer);
    if (res.ok) {
      const data = await res.json();
      if (data.status === 'success') {
        return {
          ip,
          city: data.city || fallback.city,
          region: data.regionName || fallback.region,
          country: data.country || fallback.country,
          countryCode: data.countryCode || fallback.countryCode,
          timezone: data.timezone || fallback.timezone,
          isp: data.isp || 'Broadband ISP',
          lat: data.lat,
          lon: data.lon,
          zip: data.zip
        };
      }
    }
  } catch (err) {
    // Silently proceed with fallback
  }

  return fallback;
}

function sendJson(res, statusCode, data) {
  res.writeHead(statusCode, {
    'Content-Type': 'application/json; charset=utf-8',
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, PATCH, PUT, DELETE, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization',
    'Cache-Control': 'no-store, no-cache, must-revalidate'
  });
  res.end(JSON.stringify(data));
}

function sanitizeInput(str, maxLen = 255) {
  if (typeof str !== 'string') return '';
  return str
    .replace(/<[^>]*>?/gm, '') // Strip HTML tags
    .replace(/[\u0000-\u0008\u000B-\u000C\u000E-\u001F]/g, '') // Strip control chars
    .trim()
    .slice(0, maxLen);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', chunk => {
      body += chunk;
      if (body.length > 25 * 1024 * 1024) { // 1MB payload cap to prevent DoS
        reject(new Error('Payload too large (max 25MB)'));
      }
    });
    req.on('end', () => {
      if (!body) return resolve({});
      try {
        resolve(JSON.parse(body));
      } catch (err) {
        reject(new Error('Invalid JSON: ' + err.message));
      }
    });
    req.on('error', reject);
  });
}

// Convert all orders to downloadable CSV
function generateOrdersCsv(orders) {
  const headers = [
    'Order ID',
    'Order Date',
    'Status',
    'Recipient Name',
    'Phone Number',
    'Email Address',
    'Delivery Street Address',
    'Apartment / Landmark',
    'City',
    'State',
    'PIN Code',
    'Country',
    'Stealth Instructions',
    'Ordered Items (Name x Qty [Shade])',
    'Subtotal (INR)',
    'Discount (INR)',
    'Shipping (INR)',
    'Total Amount (INR)',
    'Payment Method',
    'Payment Status',
    'Carrier',
    'Tracking Number',
    'Origin IP',
    'Origin City',
    'Origin Region',
    'Origin Country',
    'Customer Device',
    'User Agent'
  ];

  const escapeCsv = (val) => {
    if (val === null || val === undefined) return '""';
    const str = String(val).replace(/"/g, '""');
    return `"${str}"`;
  };

  const rows = orders.map(ord => {
    const c = ord.customer || {};
    const p = ord.pricing || {};
    const o = ord.origin || {};
    const s = ord.shipping || {};
    const pay = ord.payment || {};

    const itemsSummary = (ord.items || [])
      .map(it => `${it.name} x${it.quantity} (${it.shade || 'default'})`)
      .join('; ');

    return [
      escapeCsv(ord.id),
      escapeCsv(ord.formattedDate || ord.createdAt),
      escapeCsv(ord.status),
      escapeCsv(c.fullName),
      escapeCsv(c.phone),
      escapeCsv(c.email),
      escapeCsv(c.address),
      escapeCsv(c.apartment || ''),
      escapeCsv(c.city),
      escapeCsv(c.state),
      escapeCsv(c.pincode),
      escapeCsv(c.country || 'India'),
      escapeCsv(c.stealthInstructions || ''),
      escapeCsv(itemsSummary),
      escapeCsv(p.subtotal || 0),
      escapeCsv(p.discount || 0),
      escapeCsv(p.shipping || 0),
      escapeCsv(p.total || 0),
      escapeCsv(pay.method || 'UPI'),
      escapeCsv(pay.status || 'Paid'),
      escapeCsv(s.carrier || 'BlueDart Discreet Express'),
      escapeCsv(s.trackingNumber || ''),
      escapeCsv(o.ip || ''),
      escapeCsv(o.city || ''),
      escapeCsv(o.region || ''),
      escapeCsv(o.country || ''),
      escapeCsv(o.device || ''),
      escapeCsv(o.userAgent || '')
    ].join(',');
  });

  // Prepend UTF-8 BOM so Excel opens INR and emojis correctly
  return '\uFEFF' + [headers.map(escapeCsv).join(','), ...rows].join('\r\n');
}

// Compute comprehensive Admin Stats
function computeAdminStats(orders) {
  const totalRevenue = orders.reduce((sum, ord) => sum + (ord.pricing?.total || 0), 0);
  const totalOrders = orders.length;
  const avgOrderValue = totalOrders > 0 ? Math.round(totalRevenue / totalOrders) : 0;

  const statusCounts = {
    processing: 0,
    confirmed: 0,
    packaging: 0,
    shipped: 0,
    delivered: 0,
    cancelled: 0
  };

  const locationCounts = {};
  const productCounts = {};
  const paymentCounts = {};
  const uniqueEmails = new Set();

  orders.forEach(ord => {
    // Status
    const st = (ord.status || 'processing').toLowerCase();
    if (statusCounts[st] !== undefined) statusCounts[st]++;
    else statusCounts.processing++;

    // Customers
    if (ord.customer?.email) uniqueEmails.add(ord.customer.email.toLowerCase().trim());

    // Location
    const loc = ord.customer?.city || ord.origin?.city || 'Unknown';
    locationCounts[loc] = (locationCounts[loc] || 0) + 1;

    // Payment
    const pay = ord.payment?.method || 'UPI';
    paymentCounts[pay] = (paymentCounts[pay] || 0) + 1;

    // Products
    (ord.items || []).forEach(it => {
      const prodName = it.name || it.id;
      if (!productCounts[prodName]) {
        productCounts[prodName] = { name: prodName, units: 0, revenue: 0, image: it.image };
      }
      productCounts[prodName].units += (it.quantity || 1);
      productCounts[prodName].revenue += ((it.price || 0) * (it.quantity || 1));
    });
  });

  const topLocations = Object.entries(locationCounts)
    .map(([city, count]) => ({ city, count }))
    .sort((a, b) => b.count - a.count);

  const topProducts = Object.values(productCounts)
    .sort((a, b) => b.units - a.units);

  return {
    totalRevenue,
    totalOrders,
    avgOrderValue,
    uniqueCustomers: uniqueEmails.size,
    statusCounts,
    topLocations,
    topProducts,
    paymentCounts,
    recentOrders: orders.slice(0, 5)
  };
}

// Main API Handler router
async function handleApiRequest(req, res, parsedUrl) {
  const method = req.method.toUpperCase();
  const pathname = parsedUrl.pathname;

  // Handle CORS preflight
  if (method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PATCH, PUT, DELETE, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization',
      'Access-Control-Max-Age': '86400'
    });
    res.end();
    return true;
  }

  // GET /api/orders
  if (pathname === '/api/orders' && method === 'GET') {
    let orders = readOrders();
    const statusFilter = parsedUrl.searchParams.get('status');
    const query = parsedUrl.searchParams.get('q');

    if (statusFilter && statusFilter !== 'all') {
      orders = orders.filter(o => (o.status || '').toLowerCase() === statusFilter.toLowerCase());
    }

    if (query) {
      const q = query.toLowerCase();
      orders = orders.filter(o => {
        const idMatch = (o.id || '').toLowerCase().includes(q);
        const nameMatch = (o.customer?.fullName || '').toLowerCase().includes(q);
        const emailMatch = (o.customer?.email || '').toLowerCase().includes(q);
        const phoneMatch = (o.customer?.phone || '').toLowerCase().includes(q);
        const cityMatch = (o.customer?.city || '').toLowerCase().includes(q);
        return idMatch || nameMatch || emailMatch || phoneMatch || cityMatch;
      });
    }

    // Sort newest first
    orders.sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));

    sendJson(res, 200, {
      success: true,
      count: orders.length,
      orders
    });
    return true;
  }

  // GET /api/orders/export (CSV)
  if (pathname === '/api/orders/export' && method === 'GET') {
    const orders = readOrders();
    const csvData = generateOrdersCsv(orders);
    res.writeHead(200, {
      'Content-Type': 'text/csv; charset=utf-8',
      'Content-Disposition': 'attachment; filename="plezzy-customer-orders.csv"',
      'Access-Control-Allow-Origin': '*',
      'Cache-Control': 'no-cache'
    });
    res.end(csvData);
    return true;
  }

  // GET /api/admin/stats
  if (pathname === '/api/admin/stats' && method === 'GET') {
    const orders = readOrders();
    const stats = computeAdminStats(orders);
    sendJson(res, 200, {
      success: true,
      stats
    });
    return true;
  }

  // GET /api/customers
  if (pathname === '/api/customers' && method === 'GET') {
    if (fs.existsSync(CUSTOMERS_FILE)) {
      try {
        const customers = JSON.parse(fs.readFileSync(CUSTOMERS_FILE, 'utf8'));
        sendJson(res, 200, { success: true, count: customers.length, customers });
        return true;
      } catch (e) {}
    }
    const orders = readOrders();
    updateCustomerDatabase(orders);
    const customers = JSON.parse(fs.readFileSync(CUSTOMERS_FILE, 'utf8') || '[]');
    sendJson(res, 200, { success: true, count: customers.length, customers });
    return true;
  }

  // POST /api/orders (Create Order)
  if (pathname === '/api/orders' && method === 'POST') {
    try {
      const payload = await readBody(req);
      const orders = readOrders();

      const ip = getClientIp(req);
      const userAgent = req.headers['user-agent'] || '';
      const device = parseDevice(userAgent);
      const referrer = req.headers['referer'] || req.headers['referrer'] || 'Direct Storefront';

      // Geolocation lookup
      const clientHints = {
        city: payload.shipping?.city || payload.customer?.city,
        state: payload.shipping?.state || payload.customer?.state,
        country: payload.shipping?.country || payload.customer?.country,
        timezone: payload.timezone
      };
      const geo = await resolveGeo(ip, clientHints);

      const orderNumber = 1000 + orders.length + 1;
      const orderId = 'PLZ-' + Math.floor(10000 + Math.random() * 90000);
      const now = new Date();

      const options = { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true };
      const formattedDate = now.toLocaleString('en-IN', options);

      // Customer payload normalize & sanitize
      const cust = payload.shipping || payload.customer || {};
      const customer = {
        fullName: sanitizeInput(cust.fullName || cust.name || 'Anonymous Valued Customer', 120),
        phone: sanitizeInput(cust.phone || '+91 00000 00000', 30),
        email: sanitizeInput(cust.email || 'customer@plezzy.com', 120),
        address: sanitizeInput(cust.address || cust.street || 'Address on file', 250),
        apartment: sanitizeInput(cust.apartment || cust.suite || '', 100),
        city: sanitizeInput(cust.city || geo.city || 'Mumbai', 80),
        state: sanitizeInput(cust.state || geo.region || 'Maharashtra', 80),
        pincode: sanitizeInput(cust.pincode || cust.zip || '400001', 20),
        country: sanitizeInput(cust.country || geo.country || 'India', 60),
        stealthInstructions: sanitizeInput(cust.stealthInstructions || payload.stealthInstructions || '100% Plain packaging. Zero product names.', 500)
      };

      // Items normalize
      const items = (payload.items || payload.cart || []).map(it => ({
        id: it.id || 'item',
        name: it.name || 'Plezzy Sensory Essential',
        shade: it.shade || it.color || 'Signature',
        price: it.priceINR || it.price || 1350,
        quantity: it.quantity || 1,
        image: it.image || '/assets/front_view.png'
      }));

      // Calculate totals
      const subtotal = items.reduce((sum, it) => sum + (it.price * it.quantity), 0);
      const discount = payload.discountAmountINR || payload.discount || 0;
      const shipping = payload.shippingCostINR !== undefined ? payload.shippingCostINR : (subtotal >= 1000 ? 0 : 150);
      const total = Math.max(0, subtotal - discount + shipping);

      const newOrder = {
        id: orderId,
        orderNumber,
        createdAt: now.toISOString(),
        formattedDate,
        status: 'processing',
        customer,
        origin: {
          ip,
          city: geo.city,
          region: geo.region,
          country: geo.country,
          countryCode: geo.countryCode,
          timezone: geo.timezone,
          isp: geo.isp,
          device,
          userAgent,
          referrer
        },
        items,
        pricing: {
          subtotal,
          discount,
          discountCode: payload.appliedPromo || '',
          shipping,
          isFreeShipping: shipping === 0,
          total,
          currency: 'INR',
          formattedTotal: `₹${total.toLocaleString('en-IN')}`
        },
        payment: {
          method: payload.paymentMethod === 'card' ? 'Card' :
                  payload.paymentMethod === 'cod' ? 'Cash on Delivery' : 'UPI',
          status: payload.paymentMethod === 'cod' ? 'Pending upon Delivery' : 'Paid',
          stealthBillingDescriptor: 'PLZ-WLNS CO'
        },
        shipping: {
          carrier: 'BlueDart Discreet Express',
          trackingNumber: 'BD-' + Math.floor(10000000 + Math.random() * 90000000),
          estimatedDelivery: '2-3 Business Days',
          packagingType: '100% Plain Unmarked Eco-Kraft Box'
        }
      };

      orders.unshift(newOrder);
      writeOrders(orders);

      console.log(`[Order Created] Order ${newOrder.id} for ${customer.fullName} (${customer.city}, ${customer.country}) - Total: ₹${total}`);

      sendJson(res, 201, {
        success: true,
        orderId: newOrder.id,
        order: newOrder
      });
      return true;
    } catch (err) {
      console.error('[Backend] Error creating order:', err);
      sendJson(res, 500, { success: false, error: err.message });
      return true;
    }
  }

  // PATCH /api/orders/:id/status or POST /api/orders/:id/status
  const statusMatch = pathname.match(/^\/api\/orders\/([a-zA-Z0-9_-]+)\/status$/);
  if (statusMatch && (method === 'PATCH' || method === 'POST' || method === 'PUT')) {
    try {
      const orderId = statusMatch[1];
      const payload = await readBody(req);
      const orders = readOrders();
      const index = orders.findIndex(o => o.id === orderId);

      if (index === -1) {
        sendJson(res, 404, { success: false, error: 'Order not found' });
        return true;
      }

      if (payload.status) orders[index].status = sanitizeInput(payload.status, 40);
      if (payload.carrier) orders[index].shipping.carrier = sanitizeInput(payload.carrier, 100);
      if (payload.trackingNumber) orders[index].shipping.trackingNumber = sanitizeInput(payload.trackingNumber, 100);
      if (payload.adminNotes) orders[index].adminNotes = sanitizeInput(payload.adminNotes, 500);
      if (payload.paymentStatus) orders[index].payment.status = sanitizeInput(payload.paymentStatus, 60);
      orders[index].updatedAt = new Date().toISOString();

      writeOrders(orders);
      console.log(`[Order Updated] ${orderId} status set to ${payload.status}`);

      sendJson(res, 200, {
        success: true,
        order: orders[index]
      });
      return true;
    } catch (err) {
      sendJson(res, 500, { success: false, error: err.message });
      return true;
    }
  }

  // DELETE /api/orders/:id
  const deleteMatch = pathname.match(/^\/api\/orders\/([a-zA-Z0-9_-]+)$/);
  if (deleteMatch && method === 'DELETE') {
    try {
      const orderId = deleteMatch[1];
      const orders = readOrders();
      const filtered = orders.filter(o => o.id !== orderId);

      if (filtered.length === orders.length) {
        sendJson(res, 404, { success: false, error: 'Order not found' });
        return true;
      }

      writeOrders(filtered);
      console.log(`[Order Deleted] ${orderId} removed`);

      sendJson(res, 200, { success: true, deletedId: orderId });
      return true;
    } catch (err) {
      sendJson(res, 500, { success: false, error: err.message });
      return true;
    }
  }

  // GET /api/orders/:id
  const getSingleMatch = pathname.match(/^\/api\/orders\/([a-zA-Z0-9_-]+)$/);
  if (getSingleMatch && method === 'GET') {
    const orderId = getSingleMatch[1];
    const orders = readOrders();
    const order = orders.find(o => o.id === orderId);
    if (!order) {
      sendJson(res, 404, { success: false, error: 'Order not found' });
      return true;
    }
    sendJson(res, 200, { success: true, order });
    return true;
  }

    // ── PRODUCT CATALOG API ──────────────────────────────
  // GET /api/products
  if (pathname === '/api/products' && method === 'GET') {
    const products = readProducts();
    sendJson(res, 200, { success: true, count: products.length, products });
    return true;
  }

  // POST /api/products (Create Product)
  if (pathname === '/api/products' && method === 'POST') {
    try {
      const payload = await readBody(req);
      if (!payload.name) {
        sendJson(res, 400, { success: false, error: 'Product name is required' });
        return true;
      }
      const products = readProducts();
      const safeSlug = payload.slug || payload.name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
      const newId = payload.id || ('plz-' + safeSlug + '-' + Date.now().toString().slice(-4));
      
      const newProd = {
        id: newId,
        slug: safeSlug,
        name: payload.name,
        subtitle: payload.subtitle || 'sensory wellness edition',
        category: payload.category || 'sensory essentials',
        badge: payload.badge || 'new',
        role: payload.role || (payload.name + ' - daily sensory ritual'),
        tagline: payload.tagline || (payload.subtitle || ''),
        priceINR: Number(payload.priceINR) || 2499,
        priceUSD: payload.priceUSD || ('$' + Math.round((Number(payload.priceINR) || 2499) / 80) + '.00'),
        rating: Number(payload.rating) || 5.0,
        reviewsCount: Number(payload.reviewsCount) || 1,
        image: payload.image || '/assets/products/pocket-pebble-main.jpg',
        images: (payload.images && payload.images.length > 0) ? payload.images : [
          { src: payload.image || '/assets/products/pocket-pebble-main.jpg', label: 'Front Portrait' },
          { src: payload.image || '/assets/products/pocket-pebble-main.jpg', label: 'Detail View' }
        ],
        shades: (payload.shades && payload.shades.length > 0) ? payload.shades : [
          { name: 'signature', color: '#b9745d', desc: 'core finish', badge: 'signature' }
        ],
        desc1: payload.desc1 || 'Crafted with intentional sensory touch and body-safe liquid silicone for daily wellness.',
        desc2: payload.desc2 || 'Engineered with quiet acoustic dampening and magnetic rapid charging.',
        accordions: (payload.accordions && payload.accordions.length > 0) ? payload.accordions : [
          { title: 'Benefits & Sensory Ritual', content: 'Ultra-quiet acoustic dampening delivers deep physical relaxation and sensory harmony.' },
          { title: 'Application & Care', content: 'Power on with gentle touch. Safe for warm bath relaxation and effortless cleansing.' },
          { title: 'Materials & Specs', content: '100% body-safe hypoallergenic liquid silicone. Submersible waterproof seal.' },
          { title: 'Stealth Delivery & Guarantee', content: 'Dispatched in plain, unbranded protective packaging with unconditional 1-year warranty.' }
        ],
        availability: payload.availability || 'in_stock',
        price: Number(payload.priceINR) || 2499,
        priceNumeric: Number(payload.priceINR) || 2499
      };

      products.unshift(newProd);
      writeProducts(products);
      console.log('[Product Created] ' + newProd.name + ' - ' + newProd.id);
      sendJson(res, 201, { success: true, product: newProd });
      return true;
    } catch (err) {
      sendJson(res, 500, { success: false, error: err.message });
      return true;
    }
  }

  // PUT /api/products/:id (Update Product)
  const putProdMatch = pathname.match(/^\/api\/products\/([a-zA-Z0-9_-]+)$/);
  if (putProdMatch && (method === 'PUT' || method === 'PATCH')) {
    try {
      const prodId = putProdMatch[1];
      const payload = await readBody(req);
      const products = readProducts();
      const idx = products.findIndex(p => p.id === prodId);
      if (idx === -1) {
        sendJson(res, 404, { success: false, error: 'Product not found' });
        return true;
      }
      const existing = products[idx];
      const updated = {
        ...existing,
        ...payload,
        id: prodId,
        priceINR: payload.priceINR !== undefined ? Number(payload.priceINR) : existing.priceINR,
        price: payload.priceINR !== undefined ? Number(payload.priceINR) : existing.priceINR,
        priceNumeric: payload.priceINR !== undefined ? Number(payload.priceINR) : existing.priceINR
      };
      products[idx] = updated;
      writeProducts(products);
      console.log('[Product Updated] ' + prodId);
      sendJson(res, 200, { success: true, product: updated });
      return true;
    } catch (err) {
      sendJson(res, 500, { success: false, error: err.message });
      return true;
    }
  }

  // DELETE /api/products/:id
  const delProdMatch = pathname.match(/^\/api\/products\/([a-zA-Z0-9_-]+)$/);
  if (delProdMatch && method === 'DELETE') {
    try {
      const prodId = delProdMatch[1];
      const products = readProducts();
      const filtered = products.filter(p => p.id !== prodId);
      if (filtered.length === products.length) {
        sendJson(res, 404, { success: false, error: 'Product not found' });
        return true;
      }
      writeProducts(filtered);
      console.log('[Product Deleted] ' + prodId);
      sendJson(res, 200, { success: true, deletedId: prodId });
      return true;
    } catch (err) {
      sendJson(res, 500, { success: false, error: err.message });
      return true;
    }
  }

  // POST /api/upload (Image Upload)
  if (pathname === '/api/upload' && method === 'POST') {
    try {
      const payload = await readBody(req);
      const dataUrl = payload.dataUrl || payload.image || payload.file;
      if (!dataUrl) {
        sendJson(res, 400, { success: false, error: 'No image data provided' });
        return true;
      }

      if (!fs.existsSync(UPLOAD_DIR)) {
        fs.mkdirSync(UPLOAD_DIR, { recursive: true });
      }

      let matches = dataUrl.match(/^data:([A-Za-z-+\/]+);base64,(.+)$/);
      let buffer;
      let ext = '.jpg';

      if (matches && matches.length === 3) {
        const mime = matches[1];
        if (mime.includes('png')) ext = '.png';
        else if (mime.includes('webp')) ext = '.webp';
        else if (mime.includes('gif')) ext = '.gif';
        buffer = Buffer.from(matches[2], 'base64');
      } else {
        buffer = Buffer.from(dataUrl, 'base64');
      }

      const safeName = (payload.filename || 'product-image')
        .toLowerCase()
        .replace(/[^a-z0-9_-]+/g, '-')
        .replace(/(^-|-$)/g, '')
        .slice(0, 30);
      const filename = 'custom-' + Date.now() + '-' + safeName + ext;
      const filePath = path.join(UPLOAD_DIR, filename);

      fs.writeFileSync(filePath, buffer);
      const publicUrl = '/assets/products/' + filename;
      console.log('[Image Uploaded] Saved to ' + publicUrl);

      sendJson(res, 201, {
        success: true,
        url: publicUrl,
        label: payload.label || 'Product Image',
        filename
      });
      return true;
    } catch (err) {
      console.error('[Upload Error]', err);
      sendJson(res, 500, { success: false, error: err.message });
      return true;
    }
  }

return false; // Not an API route handled here
}

module.exports = {
  handleApiRequest,
  readOrders,
  writeOrders,
  computeAdminStats,
  readProducts,
  writeProducts
};
