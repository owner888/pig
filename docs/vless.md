# Proxy a Phone Through pig (VLESS)

`pig-vless` is a package (`pig install git:github.com/owner888/pig-vless`) that opens a [VLESS](https://xtls.github.io/en/development/protocols/vless.html) inbound beside the agent, so a phone running Shadowrocket, v2rayNG or sing-box can route its traffic through the machine pig is on. It has nothing to do with the agent; it shares the event loop and nothing else.

It is the smallest version a phone can use, not a replacement for Xray or sing-box:

- **TCP or WebSocket (`vless.wsPath`).** The VLESS `UDP` and `MUX` commands close the connection. Shadowrocket falls back to direct for UDP when the proxy declines it, which is fine for a phone.
- **One UUID**, made for you on first load.
- **TLS when you give it a certificate**, plain when you do not.
- **Non-blocking DNS** through `Pig\Async\Dns`.
- No REALITY, Vision, XHTTP, flow control or multi-user. If you need those, run Xray.

---

## Install & turn it on

```bash
pig install git:github.com/owner888/pig-vless
```

Once installed, it writes its settings on the first session:

```json
{
  "vless": {
    "listen": "0.0.0.0:10086",
    "uuid": "7a3f1c2e-9b4d-4e6f-8a1b-2c3d4e5f6a7b"
  }
}
```

Nothing listens until you ask. From the prompt:

```text
/vless start      # open the port
/vless status     # configuration, whether it is listening, open sockets, and the share link
/vless stop       # close the port and every relayed connection
/vless restart    # stop, then start
/vless            # same as status
```

The session ending stops it. Starting again next time is `/vless start` again — a proxy port is not something that should open on its own.

---

## Put it on the phone

`/vless status` prints the link:

```text
vless://7a3f1c2e-9b4d-4e6f-8a1b-2c3d4e5f6a7b@<this machine's address>:10086?encryption=none&security=none&type=tcp#pig
```

Replace `<this machine's address>` with the address the phone reaches the machine at — its LAN IP, or its Tailscale address — and paste the link into the client, or make a QR code of it. For the phone to reach the port the machine's firewall has to allow it; macOS asks once on first start.

---

## TLS

Point the two settings at a certificate and its key, in PEM:

```json
{
  "vless": {
    "listen": "0.0.0.0:443",
    "uuid": "…",
    "cert": "/etc/letsencrypt/live/example.com/fullchain.pem",
    "key":  "/etc/letsencrypt/live/example.com/privkey.pem"
  }
}
```

The share link then says `security=tls`. Set the client's SNI to the certificate's name. Without a certificate the connection is plain, which is fine on a LAN or over Tailscale and not fine across the internet — anything in between can read and alter it.

---

## Settings

| Key | Default | Meaning |
|---|---|---|
| `vless.listen` | `0.0.0.0:10086` | Address and port to listen on; written on first load when absent |
| `vless.uuid` | generated | The one key the phone must present; written on first load when absent |
| `vless.cert` | — | PEM certificate; with `key`, turns the listener into TLS |
| `vless.key` | — | PEM private key |
| `vless.wsPath` | — | When set (e.g. `"/vless"`), serves VLESS over WebSocket (`type=ws&path=%2Fvless`, RFC 6455 binary frames + `Sec-WebSocket-Protocol` 0-RTT early data) instead of raw TCP |

A wrong UUID, or a connection that is not VLESS at all, is closed without a reply. Saying why would tell a port scanner what is listening.
