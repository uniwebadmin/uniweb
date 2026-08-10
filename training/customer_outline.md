# Customer Portal Training Outline

Per page: purpose, who uses, main actions.
Headings in English + Hindi.

## Customer Login / ग्राहक लॉगिन
- **Purpose**: Customer authentication via phone + OTP
- **Who uses**: Customers who have made a payment
- **Main actions**: Enter 10-digit mobile → receive OTP → verify → login
- **Notes**: Rate limited: 8 login fails/5 min, 6 OTP fails/10 min. No password needed.

## Customer Portal / ग्राहक पोर्टल
- **Purpose**: View transactions, raise complaints
- **Who uses**: Customers
- **Main actions**: View payment history, check transaction status, raise support ticket
- **Notes**: Shows only this customer's transactions (matched by phone number). No wallet, no topup, no PPI.

## Customer Profile / ग्राहक प्रोफ़ाइल
- **Purpose**: View and edit profile
- **Who uses**: Customers
- **Main actions**: Change phone number (requires OTP on new number), view recent transactions
- **Notes**: Phone change updates all linked transactions, payment links, and tickets. No wallet features.

## What's NOT here / यहाँ क्या नहीं है
- No wallet / वॉलेट नहीं है
- No topup / टॉपअप नहीं है
- No PPI / PPI नहीं है
- No NBFC / NBFC नहीं है
- No crypto / क्रिप्टो नहीं है
- No bank account management / बैंक खाता प्रबंधन नहीं है

## Payment Status Page / भुगतान स्थिति पृष्ठ
- **Purpose**: Show payment success/failure to customer after checkout
- **Who uses**: Customers completing a payment
- **Main actions**: View payment confirmation, retry if failed, contact support
- **Notes**: Shows mapped reason for failures in EN + HI. Accessible without login via payment link.
