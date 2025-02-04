<?php

namespace App\Libs\Enums\Http;

/**
 * Http Status Codes
 */
enum Status: int
{
    case CONTINUE = 100;
    case SWITCHING_PROTOCOLS = 101;
    case PROCESSING = 102;                               // RFC2518
    case EARLY_HINTS = 103;                             // RFC8297
    case OK = 200;
    case CREATED = 201;
    case ACCEPTED = 202;
    case NON_AUTHORITATIVE_INFORMATION = 203;
    case NO_CONTENT = 204;
    case RESET_CONTENT = 205;
    case PARTIAL_CONTENT = 206;
    case MULTI_STATUS = 207;                            // RFC4918
    case ALREADY_REPORTED = 208;                        // RFC5842
    case IM_USED = 226;                                 // RFC3229
    case MULTIPLE_CHOICES = 300;
    case MOVED_PERMANENTLY = 301;
    case FOUND = 302;
    case SEE_OTHER = 303;
    case NOT_MODIFIED = 304;
    case USE_PROXY = 305;
    case RESERVED = 306;
    case TEMPORARY_REDIRECT = 307;
    case PERMANENTLY_REDIRECT = 308;                     // RFC7238
    case BAD_REQUEST = 400;
    case UNAUTHORIZED = 401;
    case PAYMENT_REQUIRED = 402;
    case FORBIDDEN = 403;
    case NOT_FOUND = 404;
    case METHOD_NOT_ALLOWED = 405;
    case NOT_ACCEPTABLE = 406;
    case PROXY_AUTHENTICATION_REQUIRED = 407;
    case REQUEST_TIMEOUT = 408;
    case CONFLICT = 409;
    case GONE = 410;
    case LENGTH_REQUIRED = 411;
    case PRECONDITION_FAILED = 412;
    case REQUEST_ENTITY_TOO_LARGE = 413;
    case REQUEST_URI_TOO_LONG = 414;
    case UNSUPPORTED_MEDIA_TYPE = 415;
    case REQUESTED_RANGE_NOT_SATISFIABLE = 416;
    case EXPECTATION_FAILED = 417;
    case I_AM_A_TEAPOT = 418;                            // RFC2324
    case MISDIRECTED_REQUEST = 421;                      // RFC7540
    case UNPROCESSABLE_ENTITY = 422;                     // RFC4918
    case LOCKED = 423;                                   // RFC4918
    case FAILED_DEPENDENCY = 424;                        // RFC4918
    case TOO_EARLY = 425;                                // RFC-ietf-httpbis-replay-04
    case UPGRADE_REQUIRED = 426;                         // RFC2817
    case PRECONDITION_REQUIRED = 428;                    // RFC6585
    case TOO_MANY_REQUESTS = 429;                        // RFC6585
    case REQUEST_HEADER_FIELDS_TOO_LARGE = 431;          // RFC6585
    case UNAVAILABLE_FOR_LEGAL_REASONS = 451;            // RFC7725
    case INTERNAL_SERVER_ERROR = 500;
    case NOT_IMPLEMENTED = 501;
    case BAD_GATEWAY = 502;
    case SERVICE_UNAVAILABLE = 503;
    case GATEWAY_TIMEOUT = 504;
    case VERSION_NOT_SUPPORTED = 505;
    case VARIANT_ALSO_NEGOTIATES_EXPERIMENTAL = 506;     // RFC2295
    case INSUFFICIENT_STORAGE = 507;                     // RFC4918
    case LOOP_DETECTED = 508;                            // RFC5842
    case NOT_EXTENDED = 510;                             // RFC2774
    case NETWORK_AUTHENTICATION_REQUIRED = 511;          // RFC6585
    case ORIGIN_SERVER_CONNECTION_ISSUE = 522;           // -- cloudflare cdn.
}
