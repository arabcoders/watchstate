# Authentication

WatchState supports local credentials, OpenID Connect (OIDC), and trusted-header authentication through a reverse proxy. 

> [!NOTE]
> 
> You must create the local WatchState account before enabling either external method. 
> That account remains available for local login and is the account external logins use.

## Choose a login method

- **Local login** is the default and requires no external identity service.
- **OIDC** sends users to an identity provider to authenticate in the browser.
- **Trusted-header authentication** accepts an identity asserted by a reverse proxy that you control.

You can enable OIDC and trusted-header authentication together. Local login remains available as a fallback. 
All successful external logins use the configured WatchState account and therefore have administrator access.

## Create the local account first

Create the required local WatchState account before configuring OIDC or a proxy. Test that you can sign in with its local 
credentials. Do not remove or disable this account after enabling an external method. Without the local account, you will 
not be able to sign in, regardless of the external authentication methods you enable.

## Configure OIDC

### 1. Create a confidential client

In your identity provider, create a confidential OIDC client for WatchState. Set its redirect URI to this exact URL:

```
https://watchstate.example.com/v1/api/system/auth/oidc/callback
```

The provider client must allow the `openid`, `profile`, and `email` scopes.

### 2. Add the four OIDC fields

Edit `{DATA_PATH}/config/config.yaml` and set the issuer, client ID, client secret, and redirect URI:

```yaml
auth:
  oidc:
    issuer: https://id.example.com
    client_id: watchstate
    client_secret: replace-with-client-secret
    redirect_uri: https://watchstate.example.com/v1/api/system/auth/oidc/callback
```

> [!IMPORTANT]
> 
> The redirect URI in WatchState must match the provider registration exactly, including scheme, host, path, and any deployment prefix.

### 3. Restart and verify

Restart WatchState after changing the configuration. Open the login page, select the OIDC login button, authenticate at the provider, 
and confirm that WatchState signs you in. If the provider session is still active, a later OIDC login may not ask for credentials again.

Every identity permitted to use this OIDC client becomes the one WatchState administrator. WatchState does not create separate users or 
apply provider roles and groups. Restrict access to the client with your identity provider's access policy before allowing users to log in.

Logging out of WatchState clears the local WatchState session. It does not log out of the identity provider. Use the provider's logout 
function when that session must also end.

## Configure trusted-header authentication

> [!IMPORTANT]
> 
> This method is safe only when the WatchState origin cannot be reached directly by clients.

1. Put WatchState behind an authenticated reverse proxy and make the origin reachable only from that proxy.
2. Determine the proxy's raw source address as WatchState receives it. Record the required proxy IP or CIDR, including IPv6 if applicable.
3. Configure the proxy to strip any inbound copy of the identity header, authenticate the request, and set the header itself.
4. Add the proxy settings to `{DATA_PATH}/config/config.yaml` and restart WatchState.
5. Verify that an authenticated request through the proxy signs in as the expected local account.

The default header is `Remote-User`:

```yaml
auth:
  remote_user:
    enabled: true
    trusted_proxies:
      - 127.0.0.1/32
      - ::1/128
      - 192.0.2.0/24
    # Add more trusted proxies as needed
```

For a proxy that uses another header, set `header` explicitly:

```yaml
auth:
  remote_user:
    enabled: true
    header: X-Authenticated-User
    trusted_proxies:
      - 192.0.2.10/32
```

WatchState checks the raw `REMOTE_ADDR` against `auth.remote_user.trusted_proxies` with CIDR matching. IPv4 and IPv6 are 
supported.  An empty `trusted_proxies` list fails closed, so no proxy identity is trusted. A request must come from a 
trusted address and contain a non-empty configured header. If clients can bypass the proxy or supply that header themselves, 
they can bypass the login layer entirely.

## Use OIDC and proxy authentication together

When both methods are enabled, use the OIDC button for OIDC login and the proxy entry point for requests authenticated 
by the proxy. Local credentials remain available.

## Troubleshooting

- **The OIDC button is missing or login is unavailable:** check all four `auth.oidc` values in `{DATA_PATH}/config/config.yaml`, 
  then restart WatchState. Confirm the issuer is reachable from the WatchState container.
- **The provider rejects the callback:** compare the provider's registered URI with the `auth.oidc.redirect_uri` value 
  in `{DATA_PATH}/config/config.yaml`.
- **State, nonce, or PKCE errors appear:** start a new login from the login page. Do not reuse an old callback or an expired callback.
- **Proxy login does not start:** check the raw `REMOTE_ADDR`, the `trusted_proxies` CIDR, the header name, and whether the proxy sends a non-empty value.
- **The proxy identity can be spoofed:** block direct origin access and verify that the proxy removes client-supplied identity headers before setting its own.
- **Local login fails:** confirm the original local WatchState account still exists and use its local credentials.
