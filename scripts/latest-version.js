// Version of a build of main, from the last stable tag: v1.2.3 → 1.2.4-latest.<yyyymmddhhmm>.<sha>
// PHP's version_compare() puts it after 1.2.3 and before 1.2.4, so the next stable release is offered as an update.
// Usage: node scripts/latest-version.js <last stable tag | ""> <yyyymmddhhmm> <short sha>
function latestVersion(tag, stamp, sha) {
  const m = /^v?(\d+)\.(\d+)\.(\d+)/.exec(tag || '') || [null, 0, 0, 0];
  return `${m[1]}.${m[2]}.${+m[3] + 1}-latest.${stamp}.${sha}`;
}

if (require.main === module) {
  const [tag, stamp, sha] = process.argv.slice(2);
  if (!/^\d{12}$/.test(stamp || '') || !sha) {
    console.error('usage: latest-version.js <tag> <yyyymmddhhmm> <sha>');
    process.exit(1);
  }
  console.log(latestVersion(tag, stamp, sha));
}

module.exports = { latestVersion };
