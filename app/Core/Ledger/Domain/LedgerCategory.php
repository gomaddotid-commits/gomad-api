<?php

namespace App\Core\Ledger\Domain;

enum LedgerCategory: string
{
    case BookingPaidOnline = 'BOOKING_PAID_ONLINE';
    case RentalPaid = 'RENTAL_PAID';
    case OtsFeeCollected = 'OTS_FEE_COLLECTED';
    case CollateralTopup = 'COLLATERAL_TOPUP';
    case CodFeeCollected = 'COD_FEE_COLLECTED';
    case SettlementToBusiness = 'SETTLEMENT_TO_BUSINESS';
    case WithdrawalRequest = 'WITHDRAWAL_REQUEST';
    case WithdrawalPaid = 'WITHDRAWAL_PAID';
    case WithdrawalReversal = 'WITHDRAWAL_REVERSAL';
    case RefundApproved = 'REFUND_APPROVED';
    case DepositDeducted = 'DEPOSIT_DEDUCTED';
    case PpnRemittance = 'PPN_REMITTANCE';
    case OrderPaidToGrosir = 'ORDER_PAID_TO_GROSIR';
    case PromoPlatformBorne = 'PROMO_PLATFORM_BORNE';
    case Adjustment = 'ADJUSTMENT';
}
