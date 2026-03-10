# ReactPHP + nghttp2 PHP Extension Examples

Minimal HTTP/2 client and server examples using **ReactPHP sockets** and a **native nghttp2-based PHP extension**.

This repository focuses on one practical goal: making it easy to try HTTP/2 over ReactPHP with as little setup as possible.

The examples intentionally keep the integration simple and explicit.

- **ReactPHP** provides asynchronous socket I/O
- the **nghttp2 PHP extension** provides the HTTP/2 protocol engine
- **userland code** wires the two together

This repository does not try to define a full architecture for HTTP/2 in PHP.

Instead, it shows the minimal pieces required to make the integration work today, and documents some of the boilerplate and open questions that appear when wiring ReactPHP directly to a low-level HTTP/2 session.

These examples may be useful if you want to:

- quickly try HTTP/2 over ReactPHP
- study how a native protocol engine can be connected to async PHP I/O
- experiment with your own HTTP/2 library design
- understand the current friction points in this integration style

Some of the current issues are not specific to this repository, but come from the surrounding APIs and layering, such as:

- repetitive wiring between socket events and the HTTP/2 session
- access to TLS / ALPN negotiation details
- questions around HTTP/1.1 Upgrade and h2c handling when the protocol engine itself is HTTP/2-only

The goal of this repository is to provide a small, runnable starting point first, and to make these integration issues easier to discuss with concrete code.

---

## Quick Start

Prerequisites:

- PHP 8.2 or later
- Composer
- ReactPHP dependencies (installed via `composer install`)
- nghttp2 PHP extension (`nghttp2.so`)

The extension must provide at least these classes:

- `Varion\\Nghttp2\\Session`
- `Varion\\Nghttp2\\Events\\HeadersReceived`
- `Varion\\Nghttp2\\Events\\DataReceived`
- `Varion\\Nghttp2\\Events\\StreamClosed`
- `Varion\\Nghttp2\\Events\\StreamReset`

### 1. Build `nghttp2.so`

Build from source (<https://github.com/varionlabs/ext-nghttp2>):

```bash
git clone https://github.com/varionlabs/ext-nghttp2.git
cd ext-nghttp2
phpize
./configure --enable-nghttp2
make -j"$(nproc)"
php -d extension=$(pwd)/modules/nghttp2.so -m | grep nghttp2
cd ..
```

### 2. Set `NGHTTP2_EXT_PATH`

Point `NGHTTP2_EXT_PATH` to the built module path so wrapper scripts can pass `-d extension=...` to PHP:

```bash
export NGHTTP2_EXT_PATH=/absolute/path/to/ext-nghttp2/modules/nghttp2.so
```

You can also set it only for one command:

```bash
NGHTTP2_EXT_PATH=/absolute/path/to/ext-nghttp2/modules/nghttp2.so ./bin/client
```

### 3. Install Composer dependencies

Clone this repository first:

```bash
git clone https://github.com/masakielastic/reactphp-nghttp2-examples.git
cd reactphp-nghttp2-examples
```

Then install dependencies:

```bash
composer install
```

### 4. Run `/bin/client`

Default request:

```bash
./bin/client
```

Custom target:

```bash
./bin/client nghttp2.org 443 /httpbin/get
```

### 5. Run `bin/server`

Start the local demo server:

```bash
./bin/server
```

---

## Future Work

- Reorganize the `Overview` section and architecture diagram.
- Add step-by-step client/server flow explanations.
- Document error-handling guidance and operational caveats.
