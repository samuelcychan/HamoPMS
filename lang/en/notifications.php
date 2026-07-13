<?php

return [
    'reservation_confirmation' => [
        'subject' => 'Reservation #:booking_id confirmed at :property_name',
        'body' => "Hello :guest_name,\n\nYour reservation #:booking_id is confirmed.\nProperty: :property_name\nRoom type: :room_type\nCheck-in: :check_in\nCheck-out: :check_out\nGuests: :guests\n\nThank you for choosing :property_name.",
    ],
    'reservation_modification' => [
        'subject' => 'Reservation #:booking_id updated',
        'body' => "Hello :guest_name,\n\nYour reservation #:booking_id has been updated.\nProperty: :property_name\nRoom type: :room_type\nCheck-in: :check_in\nCheck-out: :check_out\nGuests: :guests\nRate difference: :rate_difference\n\nPlease contact the property if you did not request this change.",
    ],
    'reservation_cancellation' => [
        'subject' => 'Reservation #:booking_id cancelled',
        'body' => "Hello :guest_name,\n\nReservation #:booking_id at :property_name has been cancelled.\nCancellation policy: :policy\nCancellation penalty: :penalty\n\nPlease contact the property if you have questions.",
    ],
    'payment_receipt' => [
        'subject' => 'Payment receipt for reservation #:booking_id',
        'body' => "Hello :guest_name,\n\nWe received your payment for reservation #:booking_id at :property_name.\nAmount: :amount :currency\nPayment method: :method\nReceipt reference: :payment_id\n\nThank you.",
    ],
];
