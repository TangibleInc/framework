<?php declare( strict_types=1 );
/**
 * The Handler class file.
 *
 * @package @tangible/framework
 */

namespace Tangible\RequestHandler;

/**
 * Alias for PluralHandler for backwards compatibility.
 *
 * New code should use PluralHandler or SingularHandler directly
 * depending on the type of object being handled.
 *
 * @deprecated Use PluralHandler for PluralObject or SingularHandler for SingularObject.
 * @see PluralHandler
 * @see SingularHandler
 */
class Handler extends PluralHandler {
}
