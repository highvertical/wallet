<?php
function config($key, $default = null) {
    $map = [
        'wallet.currencies.NGN.decimal_places' => 2,
        'wallet.currencies.USD.decimal_places' => 2,
        'wallet.currencies.JPY.decimal_places' => 0,
    ];
    return $map[$key] ?? $default;
}

$root = '/Applications/XAMPP/xamppfiles/htdocs/my-package-app/packages/highvertical/wallet/src';
require $root.'/Domain/Exceptions/WalletException.php';
require $root.'/Domain/Exceptions/InvalidAmountException.php';
require $root.'/Domain/Exceptions/CurrencyMismatchException.php';
require $root.'/Domain/ValueObjects/Money.php';

use Highvertical\Wallet\Domain\ValueObjects\Money;

function assertEq($expected, $actual, $label) {
    $pass = $expected === $actual;
    echo ($pass ? "PASS" : "FAIL")." $label: expected=".var_export($expected, true)." actual=".var_export($actual, true)."\n";
    if (!$pass) { global $failures; $failures = ($failures ?? 0) + 1; }
}

assertEq(500000, Money::fromDecimal('5000.00', 'NGN')->minorUnits(), 'fromDecimal 5000.00 NGN');
assertEq('5000.00', Money::fromMinorUnits(500000, 'NGN')->toDecimal(), 'toDecimal 500000 NGN');
assertEq(500000, Money::fromDecimal('5000', 'NGN')->minorUnits(), 'fromDecimal no fraction');
assertEq(-150000, Money::fromDecimal('-1500.00', 'NGN')->minorUnits(), 'fromDecimal negative');
assertEq('-1500.00', Money::fromMinorUnits(-150000, 'NGN')->toDecimal(), 'toDecimal negative');
assertEq(5, Money::fromDecimal('0.05', 'NGN')->minorUnits(), 'fromDecimal small fraction');
assertEq('0.05', Money::fromMinorUnits(5, 'NGN')->toDecimal(), 'toDecimal small fraction round trip');
assertEq(1000, Money::fromDecimal('1000', 'JPY')->minorUnits(), 'fromDecimal JPY zero decimals');
assertEq('1000', Money::fromMinorUnits(1000, 'JPY')->toDecimal(), 'toDecimal JPY');
assertEq('0.00', Money::zero('NGN')->toDecimal(), 'zero NGN');

// arithmetic
$a = Money::fromDecimal('100.00', 'NGN');
$b = Money::fromDecimal('30.00', 'NGN');
assertEq('130.00', $a->add($b)->toDecimal(), 'add');
assertEq('70.00', $a->subtract($b)->toDecimal(), 'subtract');
assertEq(true, $a->isGreaterThan($b), 'isGreaterThan');
assertEq(false, $a->isLessThan($b), 'isLessThan');
assertEq(true, $a->equals(Money::fromDecimal('100.00', 'ngn')), 'equals case-insensitive currency');

// error cases
foreach (['1e10', 'NaN', 'Infinity', '1.2.3', 'abc', ''] as $bad) {
    try {
        Money::fromDecimal($bad, 'NGN');
        echo "FAIL rejects bad input '$bad': no exception thrown\n";
        $failures = ($failures ?? 0) + 1;
    } catch (\Highvertical\Wallet\Domain\Exceptions\InvalidAmountException $e) {
        echo "PASS rejects bad input '$bad'\n";
    }
}

try {
    Money::fromDecimal('1.234', 'NGN'); // 3 decimals, NGN allows 2
    echo "FAIL rejects too many decimals: no exception\n";
    $failures = ($failures ?? 0) + 1;
} catch (\Highvertical\Wallet\Domain\Exceptions\InvalidAmountException $e) {
    echo "PASS rejects too many decimals\n";
}

try {
    $a->add(Money::fromDecimal('10.00', 'USD'));
    echo "FAIL currency mismatch: no exception\n";
    $failures = ($failures ?? 0) + 1;
} catch (\Highvertical\Wallet\Domain\Exceptions\CurrencyMismatchException $e) {
    echo "PASS currency mismatch throws\n";
}

echo "\n".($failures ?? 0)." failure(s)\n";
