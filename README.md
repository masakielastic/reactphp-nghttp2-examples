# ReactPHP + nghttp2 PHP Extension Examples

Minimal HTTP/2 client and server examples using **ReactPHP sockets** and a **native nghttp2-based PHP extension**.

This repository demonstrates how a native HTTP/2 protocol engine can be combined with the asynchronous I/O model provided by ReactPHP.

The examples intentionally keep the code simple and avoid introducing additional abstractions.  
Instead, they show the **minimal wiring required** to connect the following layers:

- **ReactPHP** – event loop and socket transport
- **nghttp2 PHP extension** – HTTP/2 protocol state machine
- **userland code** – integration between the two

In this design:

ReactPHP is responsible for network I/O and connection lifecycle.  
The nghttp2 extension is responsible for HTTP/2 protocol logic such as frame parsing, stream state management, and protocol events.  
Userland code connects the two by forwarding incoming bytes to the HTTP/2 session and sending outbound frames back to the socket.

The goal of this repository is **not to provide a complete HTTP/2 client or server library**.

Instead, it serves as a **minimal integration example** showing how a protocol engine like nghttp2 can be used together with ReactPHP.

Developers interested in building their own HTTP/2 libraries or experimenting with HTTP/2 protocol behavior may find these examples useful as a starting point.

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

## Overview

This example separates responsibilities between two layers.

### Transport layer (ReactPHP)

- TCP / TLS connection
- event loop
- socket read/write

### Protocol layer (nghttp2 extension)

- HTTP/2 session state machine
- frame encoding / decoding
- stream lifecycle
- protocol events

ReactPHP delivers raw bytes from the socket, and the nghttp2 session converts them into HTTP/2 events.

```text
ReactPHP Connector / TLS socket
        |
        | raw bytes
        v
ReactPHP ConnectionInterface
        |
        | on('data')
        v
Varion\Nghttp2\Session::receive()
        |
        | produces events
        v
HeadersReceived
DataReceived
StreamClosed
StreamReset
        |
        | outbound frames
        v
Session::drainOutput()
        |
        v
ReactPHP socket write()
```

This separation makes it possible to build higher-level HTTP/2 abstractions without mixing protocol logic with transport logic.

---

## What This Example Demonstrates

This minimal example shows how to:

- open a TLS connection using ReactPHP
- request HTTP/2 using ALPN
- create an nghttp2 client session
- send the HTTP/2 client preface
- submit a request
- receive HTTP/2 frames
- process protocol events

Typical flow:

1. Establish TLS connection.
2. Negotiate `h2` using ALPN.
3. Create a `Session` object.
4. Send client preface and SETTINGS.
5. Submit a request.
6. Receive frames.
7. Handle events.
8. Detect stream completion.

---

## What This Example Does Not Cover

This example intentionally keeps the implementation simple.

It does not include:

- multiplexed streams
- flow control management
- request abstraction
- connection pooling
- advanced error handling
- GOAWAY handling
- graceful shutdown
- retry logic
- server push

The goal is **clarity**, not completeness.

---

## Why This Architecture

ReactPHP is an excellent foundation for asynchronous network programming in PHP.

However, implementing a full HTTP/2 protocol stack in pure PHP can be complex and inefficient.

Using **nghttp2 via a PHP extension** allows the protocol logic to run in native code while keeping application logic in userland.

Benefits of this design:

- efficient HTTP/2 frame processing
- simple integration with existing event loops
- clear separation between I/O and protocol state
- reusable protocol engine

This pattern is common in many networking stacks:

1. Application
2. Protocol Engine
3. Transport / Event Loop
4. Operating System

In this demo:

1. Application logic
2. nghttp2 PHP extension
3. ReactPHP
4. OS / TCP / TLS

---

## How the Client Works

The client follows a simple sequence.

### 1. Create TLS connection

ReactPHP establishes a TLS connection using `Connector`.
The client requests HTTP/2 using ALPN.

### 2. Verify protocol

After the TLS handshake, the negotiated protocol is checked (`h2`).
If HTTP/2 is not negotiated, the client aborts.

### 3. Create HTTP/2 session

An nghttp2 client session is created.
This object manages the HTTP/2 connection state.

### 4. Send client preface

HTTP/2 requires a client preface followed by SETTINGS.
The session generates the required frames.
These frames must be written to the socket.

### 5. Submit request

The client submits a GET request.
This schedules HEADERS frames for transmission.

### 6. Flush outbound frames

Frames generated by the session are retrieved using:

```php
Session::drainOutput()
```

These frames are written to the socket.

### 7. Receive data

When the socket receives bytes, they are passed to:

```php
Session::receive()
```

The session parses frames and generates events.

### 8. Handle events

Typical events include:

- `HeadersReceived`
- `DataReceived`
- `StreamClosed`
- `StreamReset`

These events represent **protocol-level events**, not socket events.

### 9. Detect completion

When `StreamClosed` is received, the request is complete.

---

## Event Model

The nghttp2 extension converts HTTP/2 frames into PHP event objects.

- `HeadersReceived`: response headers have been received.
- `DataReceived`: a chunk of response body data has been received.
- `StreamClosed`: the stream finished normally.
- `StreamReset`: the stream was terminated with `RST_STREAM`.

These events correspond to the internal HTTP/2 state machine.

---

## Important Design Notes

There are a few important concepts demonstrated in this example.

### HTTP/2 is frame-driven

HTTP/2 communication is not request/response oriented internally.
It is a sequence of frames flowing over a connection.

### `submitRequest` does not send data immediately

Submitting a request only queues frames.
Actual transmission happens when calling:

```php
Session::drainOutput()
```

### `receive` may produce outbound frames

Receiving frames may require sending ACK or `WINDOW_UPDATE` frames.
Therefore it is important to flush output **after processing input**.

---

## Future Directions

This repository only shows the lowest-level integration.

Possible next steps include:

- HTTP/2 client abstraction
- multiplexed request handling
- request/response API
- connection pooling
- server implementation
- integration with ReactPHP HTTP components

It may also serve as a foundation for experiments with:

- HTTP/3
- QUIC transports
- generic transport APIs

---

## Related Projects

- ReactPHP: <https://reactphp.org/>
- nghttp2: <https://nghttp2.org/>
