# Reservation cancellation policies

`POST /api/v1/bookings/{bookingId}/cancel` cancels a pending or confirmed reservation under its named policy. Policy definitions live in `config/cancellation.php`; the default policy and free-window hours can be overridden through environment variables.

The built-in policies are:

| Policy | Free window | Late penalty |
| --- | --- | --- |
| `flexible` | At least 24 hours before arrival | First night |
| `standard` | At least 48 hours before arrival | First night |
| `non_refundable` | None | 100% of the room total |

The free-window boundary is inclusive. The policy engine also supports `none`, `fixed`, `percentage`, and `first_night` penalty types for configured policies, and caps a penalty at the reservation room total. New reservations snapshot both the policy terms and nightly rate, so later configuration or room-type pricing changes do not rewrite the guest's cancellation terms.

Cancellation is transactional. The folio receives an immutable room-rate reversal and, when applicable, a cancellation penalty, leaving the room portion of the balance equal to the penalty. The booking records `cancelled_at`, `cancelled_by`, `cancellation_policy`, and `cancellation_penalty`, then dispatches `ReservationCancelled` for reporting.
