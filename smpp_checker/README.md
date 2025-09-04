# SMPP Connectivity Check (PHP)
### Version: 1.0
A minimal standalone PHP CLI tool to verify **SMPP connectivity** and **bind status** against an SMSC.  
Useful for troubleshooting when software like Kannel is stuck in a reconnecting loop and you want to test the bind directly.

---

## Features
- Supports **bind_transmitter (TX)**, **bind_receiver (RX)**, and **bind_transceiver (TRX)**.
- Plain TCP or **TLS** connections.
- Logs success, SMPP errors, or TCP closure reasons.
- Optionally sends `enquire_link` to confirm session liveness.
- Exits with `0` on success, `1` on failure (for use in scripts/monitoring).

---

## Usage

```bash
php smpp_check.php \
    --host=HOST --port=PORT   --system_id=USER --password='PASSWORD' \
    [--bind=trx|tx|rx] [--system_type=] \
    [--addr_ton=0] [--addr_npi=0] [--address_range=]   [--timeout=10] [--read_timeout=10] \
    [--enquire] [--tls] [--log=/path/to/file.log] \
    [--verbose]
```

### Examples

Check with **TRX bind** on plain TCP:
```bash
php smpp_check.php --host=smsc.example.com --port=2775   --system_id=myuser --password='pa#ss@word!'   --bind=trx --enquire --verbose
```

Check with **TX bind** over TLS, logging to file:
```bash
php smpp_check.php --host=xx.xx.xx.xx --port=2775   --system_id=myuser --password='mypass'   --bind=tx --tls --log=./smpp_check.log
```

---

## Handling Special Characters in Passwords
When passing credentials on the CLI, wrap them in **single quotes** to avoid shell interpretation:

```bash
--password='pa#ss@word!'
```

If the password itself contains a single quote `'`, either:
```bash
--password="pa'ss#@"
```
or
```bash
--password='pa'\''ss#@'
```

---

## Return Codes
- `0` – Successful bind (and enquire link if enabled).
- `1` – TCP failure, bind refused, or session closed unexpectedly.

---

## Typical Errors & Fixes
- `connection closed while reading` → SMSC dropped the socket. Common causes:
  - Wrong **TLS/plain** mode
  - Unsupported bind mode (try `--bind=tx`)
  - IP not whitelisted
  - Wrong credentials
- No response to `enquire_link` → SMSC not handling keepalive properly.

---

## Notes
- Default SMPP version is **3.4** (`0x34`).
- Extend the script if your SMSC requires **3.3** (`0x33`) or other versions.
- For debugging deeper, capture traffic:
  ```bash
  sudo tcpdump -i any host <SMSC_IP> and port <PORT> -w smpp_check.pcap
  ```

---

## License
GNU General Public License v2.0

---

## Versioning
- 1.0 Base version tested

---