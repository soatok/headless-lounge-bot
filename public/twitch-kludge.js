/*
 * This is the other half of the kludge we're forced to write to get
 * Twitch's API to behave correctly.
 */
if (document.location.hash.match(
    /^access_token=([A-Za-z0-9]+)&scope=([a-z0-9_\-\s]+)&state=([A-Za-z0-9\-_]+)&token_type=([a-z]+)$/
)) {
    // Nothing funny going on here...
    window.location = "/authorize/twitch?" + document.location.hash;
} else {
    // Oh hooooowl no!
    console.error("Possible open redirect vulnerability");
    window.location = "/#possible-open-redirect-vulnerability";
}
