#!/bin/sh
# wisso-smoke.sh: does the wisso app still answer after a snap refresh or any change?
# Needs no key and changes nothing. Run it after EVERY Nextcloud snap refresh (daily window 04:00-05:00 UTC).
#
#   sh wisso-smoke.sh                       # checks https://path.wi.sa
#   sh wisso-smoke.sh https://host [-k]     # another base URL; -k accepts a self-signed certificate (local rig)
#
# Exit 0 = all checks passed, 1 = a check failed (read the line that says FAIL), 2 = could not run.
# What each check proves:
#   1. a wrong bearer key answers HTTP 401 with the JSON body {"errors":[{"title":"Unauthorized."}]}: the app is enabled, its
#      route answers, the Authorization header reaches PHP, and a wrong key is refused. A broken or disabled app answers
#      404 or an HTML page instead.
#   2. GET on the login call answers 405 JSON: wi's error shape holds for a non-POST request.
#   3. an unknown path under admin-api answers 404 JSON.
#   4. the consume page with a junk code redirects to the login page (302 or 303) and shows no reason.
# Tested on the rig: 4 PASS when healthy; with the app disabled, all 4 FAIL (404 HTML). Nextcloud caches its route table for a few
# seconds, so right after an enable or disable wait about 10 s before judging a result.
# It cannot prove that the wi key is right or that a real sign-in works: do one real click from wi in a private window.
set -u
BASE="${1:-https://path.wi.sa}"
CURL_OPTS=""
[ "${2:-}" = "-k" ] && CURL_OPTS="-k"
APP="${BASE%/}/index.php/apps/wisso"
command -v curl >/dev/null 2>&1 || { echo "curl is not installed"; exit 2; }

fail=0
junk_key="$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' | head -c 43)"
EXPECT_401='{"errors":[{"title":"Unauthorized."}]}'

check() { # name, expected status, expected body (or "-" for none), actual status, actual body
	if [ "$4" = "$2" ] && { [ "$3" = "-" ] || [ "$5" = "$3" ]; }; then
		echo "PASS  $1"
	else
		echo "FAIL  $1 (status $4, expected $2; body: $(printf %s "$5" | head -c 120))"
		fail=1
	fi
}

out="$(curl -sS $CURL_OPTS -m 20 -w '\n%{http_code}' -X POST -H "Authorization: Bearer $junk_key" -d 'email=smoke@example.invalid' "$APP/admin-api/sso/login" 2>&1)" || { echo "could not reach $BASE"; exit 2; }
check "wrong key is refused with JSON 401" 401 "$EXPECT_401" "$(printf %s "$out" | tail -n 1)" "$(printf %s "$out" | sed '$d')"

out="$(curl -sS $CURL_OPTS -m 20 -w '\n%{http_code}' "$APP/admin-api/sso/login" 2>&1)"
check "GET on login is 405 JSON" 405 '{"errors":[{"title":"Method not allowed."}]}' "$(printf %s "$out" | tail -n 1)" "$(printf %s "$out" | sed '$d')"

out="$(curl -sS $CURL_OPTS -m 20 -w '\n%{http_code}' "$APP/admin-api/nothing-here" 2>&1)"
check "unknown admin-api path is 404 JSON" 404 '{"errors":[{"title":"Not found."}]}' "$(printf %s "$out" | tail -n 1)" "$(printf %s "$out" | sed '$d')"

code="$(curl -sS $CURL_OPTS -m 20 -o /dev/null -w '%{http_code} %{redirect_url}' "$APP/sso/consume?code=smokesmokesmokesmokesmokesmokesmokesmokesm" 2>&1)"
case "$code" in
	"302 ${BASE%/}/index.php/login"|"303 ${BASE%/}/index.php/login") echo "PASS  consume with a junk code goes to the login page" ;;
	*) echo "FAIL  consume with a junk code (got: $code)"; fail=1 ;;
esac

if [ "$fail" = 0 ]; then echo "SMOKE: PASS"; exit 0; fi
echo "SMOKE: FAIL"
exit 1
