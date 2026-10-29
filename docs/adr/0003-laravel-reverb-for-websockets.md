# ADR 0003: First-Party Laravel Reverb for Real-Time WebSockets

## Status
**ACCEPTED** (2026-10-30)

## Context
The POS system requires instant multi-counter synchronization (e.g. Counter 1 sells an item -> Counter 2 & Counter 3 screens immediately reflect the updated stock without page refresh; Workshop Bay status changes are broadcasted in real time).
Options considered:
1. **Third-Party SaaS (Pusher / Ably):** Expensive recurring costs for local retail deployments, dependency on external cloud connectivity.
2. **Custom Node.js Socket.io Server:** Requires maintaining a separate daemon, manual authentication token validation, and custom event bridging.
3. **Laravel Reverb:** Official first-party WebSocket server introduced in Laravel 11.

## Decision
We select **Laravel Reverb (`laravel/reverb`)**:
* Fully integrated with Laravel's Event Broadcasting (`implements ShouldBroadcast`).
* Runs as a high-performance native PHP asynchronous WebSocket server on port 8080.
* Compatible with standard `pusher-js` and `laravel-echo` on the Next.js frontend.
* Zero external SaaS subscription costs; completely self-hostable on Docker and GCP VMs.

## Consequences
* **Positive:** Native integration with Laravel Sanctum authentication and private channels.
* **Positive:** Directly satisfies the Enrich Insights / Tech Mart MM JD requirement ("Laravel Reverb, Pusher, or Socket.io").
* **Positive:** Redis-backed horizontal scaling capability for multi-branch clustering.
