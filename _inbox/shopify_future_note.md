# Shopify App — Future Work Note

## Kya hai
Shopify store wale merchants apne shop mein UniWeb payment gateway integrate kar sakein.

## Kaise banega
- Ek Shopify App (public/custom app) banega — alag app server pe
- Merchant Shopify admin se app install karega
- App UniWeb merchant account se API key se link hoga
- Shopify checkout pe UniWeb payment option dikhenga (UPI, cards, etc.)
- Order aate hi UniWeb API ko webhook jaayega, payment link ban jayega
- Customer pay karega → money merchant ke UniWeb wallet mein aayega

## Status
- Owner ne bola: "chhodo abhi, baad ke liye note mein daal do"
- Jab owner bole "start karo", tab implement karna

## Architecture note
Shopify app ke liye alag app server chahiye (Shopify ki requirement).
Ye UniWeb ke PHP app ke saath API se connect hoga.
