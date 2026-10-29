const express = require('express');
const cors = require('cors');
const bwipjs = require('bwip-js');
const Redis = require('ioredis');

const app = express();
const PORT = process.env.PORT || 3001;
const REDIS_HOST = process.env.REDIS_HOST || '127.0.0.1';
const REDIS_PORT = process.env.REDIS_PORT || 6379;

app.use(cors());
app.use(express.json());

// Initialize Redis Client for Pub/Sub and Queues
let redisSubscriber;
try {
  redisSubscriber = new Redis({
    host: REDIS_HOST,
    port: REDIS_PORT,
    retryStrategy: (times) => Math.min(times * 100, 3000)
  });

  redisSubscriber.subscribe('pos:print-job', 'pos:barcode-gen', (err, count) => {
    if (err) {
      console.warn('[NodeWorker] Redis subscription error:', err.message);
    } else {
      console.log(`[NodeWorker] Subscribed to ${count} Redis channels`);
    }
  });

  redisSubscriber.on('message', (channel, message) => {
    console.log(`[NodeWorker] Received async event on channel '${channel}':`, message);
  });
} catch (e) {
  console.warn('[NodeWorker] Running without Redis connection:', e.message);
}

// Health check endpoint
app.get('/health', (req, res) => {
  res.json({
    status: 'ONLINE',
    service: 'DOT POS Node.js Microservice Worker',
    uptime: process.uptime(),
    timestamp: new Date().toISOString()
  });
});

// 1. High-Speed Barcode / QR Code Generation
app.post('/api/v1/barcode/generate', async (req, res) => {
  try {
    const { text, bcid = 'code128', scale = 3, height = 10, includeText = true } = req.body;
    if (!text) {
      return res.status(400).json({ success: false, error: 'text parameter is required' });
    }

    const png = await bwipjs.toBuffer({
      bcid: bcid,       // 'code128', 'qrcode', 'ean13'
      text: text,
      scale: scale,
      height: height,
      includetext: includeText,
      textxalign: 'center',
    });

    res.json({
      success: true,
      data: {
        format: 'image/png',
        base64: `data:image/png;base64,${png.toString('base64')}`
      }
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// 2. ESC/POS Thermal Receipt Byte Generation (80mm / 58mm)
app.post('/api/v1/receipt/escpos', (req, res) => {
  try {
    const { orderNo, customerName, vehiclePlate, items = [], totalAmount, paymentSplits = [] } = req.body;
    
    // ESC/POS Commands
    const ESC = 0x1B;
    const GS = 0x1D;
    const commands = [];

    // Initialize Printer
    commands.push(ESC, 0x40);

    // Header (Center align, Bold, Double height)
    commands.push(ESC, 0x61, 0x01); // Center
    commands.push(ESC, 0x45, 0x01); // Bold ON
    commands.push(Buffer.from('DOT AUTO WORKSHOP & POS\n', 'utf-8'));
    commands.push(ESC, 0x45, 0x00); // Bold OFF
    commands.push(Buffer.from('Branch: Sanchaung, Yangon\n', 'utf-8'));
    commands.push(Buffer.from('--------------------------------\n', 'utf-8'));

    // Left Align Info
    commands.push(ESC, 0x61, 0x00); // Left
    commands.push(Buffer.from(`Order: ${orderNo || 'N/A'}\n`, 'utf-8'));
    commands.push(Buffer.from(`Date:  ${new Date().toLocaleString()}\n`, 'utf-8'));
    if (vehiclePlate) {
      commands.push(Buffer.from(`Plate: ${vehiclePlate}\n`, 'utf-8'));
    }
    if (customerName) {
      commands.push(Buffer.from(`Cust:  ${customerName}\n`, 'utf-8'));
    }
    commands.push(Buffer.from('--------------------------------\n', 'utf-8'));

    // Items
    items.forEach(item => {
      const line = `${item.name.slice(0, 18).padEnd(18)} ${String(item.qty).padStart(2)}x ${String(item.subtotal).padStart(8)}\n`;
      commands.push(Buffer.from(line, 'utf-8'));
    });
    commands.push(Buffer.from('--------------------------------\n', 'utf-8'));

    // Total (Right Align, Bold)
    commands.push(ESC, 0x61, 0x02); // Right
    commands.push(ESC, 0x45, 0x01); // Bold
    commands.push(Buffer.from(`TOTAL: ${totalAmount} Ks\n`, 'utf-8'));
    commands.push(ESC, 0x45, 0x00); // Bold OFF

    // Payment Methods
    paymentSplits.forEach(p => {
      commands.push(Buffer.from(`${p.method}: ${p.amount} Ks\n`, 'utf-8'));
    });

    // Footer
    commands.push(ESC, 0x61, 0x01); // Center
    commands.push(Buffer.from('\nThank You! Please Visit Again.\n\n', 'utf-8'));

    // Paper Cut
    commands.push(GS, 0x56, 0x41, 0x00);

    const buffer = Buffer.concat(commands.map(c => typeof c === 'number' ? Buffer.from([c]) : c));

    res.json({
      success: true,
      data: {
        orderNo,
        byteLength: buffer.length,
        base64Buffer: buffer.toString('base64')
      }
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.listen(PORT, '0.0.0.0', () => {
  console.log(`[NodeWorker] Service running on http://0.0.0.0:${PORT}`);
});
