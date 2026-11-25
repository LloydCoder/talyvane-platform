<?php
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Talyvane\Domain\Money;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Features\FeatureRegistry;
use Talyvane\Http\Request;
use Talyvane\Http\Response;
use Talyvane\Http\Router;
use Talyvane\Security\PasswordHasher;

$tests = 0;
$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$assert(Money::fromDecimal('123.45', 'USD', 2)->minorUnits === 12345, 'two-decimal money uses integer minor units');
$assert(Money::fromDecimal('120', 'JPY', 0)->toDecimal() === '120', 'zero-decimal currency formats correctly');
$assert(Money::fromDecimal('1.234', 'KWD', 3)->toDecimal() === '1.234', 'three-decimal currency formats correctly');
try { Money::fromDecimal('1.001', 'USD', 2); $assert(false, 'excess currency precision rejected'); }
catch (InvalidArgumentException) { $assert(true, 'excess currency precision rejected'); }
try { Money::fromDecimal('1.00', 'USD', 2)->plus(Money::fromDecimal('1.00', 'EUR', 2)); $assert(false, 'mixed currencies rejected'); }
catch (InvalidArgumentException) { $assert(true, 'mixed currencies rejected'); }

$router = new Router();
$router->add('GET', '/health/{component}', static fn(Request $r, array $p): Response => Response::json(['component'=>$p['component']]));
$response = $router->dispatch(new Request('GET', '/health/database'));
$assert($response->status === 200 && $response->body === '{"component":"database"}', 'router extracts named path parameters');
$assert($router->dispatch(new Request('GET', '/missing'))->status === 404, 'router returns 404 for unknown routes');
try { Response::redirect('//evil.example'); $assert(false, 'external protocol-relative redirects rejected'); }
catch (InvalidArgumentException) { $assert(true, 'external protocol-relative redirects rejected'); }

$features = new FeatureAvailability(
    ['bookings.manual'=>true,'bookings.automatic'=>true],
    static fn(string $gate): bool => $gate !== 'market.policy'
);
$assert(!$features->enabled('bookings.manual'), 'manual bookings fail closed without approved market policy');
$assert(!FeatureRegistry::known('security.disable_authorization'), 'unknown feature keys are rejected');

$passwordHash = PasswordHasher::hash('Correct-Horse-Battery-77!');
$assert(PasswordHasher::verify('Correct-Horse-Battery-77!', $passwordHash), 'password hash verifies');
$assert(!PasswordHasher::verify('wrong-password', $passwordHash), 'wrong password rejected');

fwrite(STDOUT, sprintf("Native PHP foundation: %d assertions passed.\n", $tests));
