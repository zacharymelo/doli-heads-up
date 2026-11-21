/* Heads-up: a placeholder, kept on purpose.
 *
 * Up to 0.3.21 the module's script was this file, which Dolibarr links from
 * the page head with no version in its address. After an update, browsers
 * (Safari above all) kept running the copy they had cached. The script is now
 * js/headsup-main.js, linked from the page footer with the file's date
 * (ActionsHeadsup::printCommonFooter), and the module no longer declares this
 * file. Installs that have not re-enabled the module still link it, so it
 * stays, says it is only a placeholder, and does nothing else.
 */
window.HEADSUP_STUB = 1;
