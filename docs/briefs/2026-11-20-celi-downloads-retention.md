# Brief — data/downloads is 3.0 GB and grows about 1 GB per month

Status: ready · Owner: Sonnet · Written by: celibidache, 2026-11-20

The hub (`~/devbox/celibidache`) observes this project from the outside. It does not change it. The numbers below are from 2026-11-19 and 2026-11-20. Check them again before you act.

## Problems

1. **`data/downloads` is 3.0 GB** on a disk with 6.4 GB free. By file month: 2026-09 0.82 GB (2,202 files), 2026-10 1.31 GB (6,926 files), 2026-11 0.75 GB so far (4,722 files).
2. **No copy off the server is visible to the hub.** `ops/pull-backup.sh` on the Mac does not leave a mark on the VPS.
3. **No persisted check verdict; `logs/alerts.log` has no rotation.**

## Do

1. State which files in `data/downloads` the pipeline reads again after the first parse. The last run log says "1 adopted, 651 cached, 140 downloaded": the cache is in use.
2. Propose a retention rule: files older than N days move to the volume (`/mnt/HC_Volume_105444571`) or to the Mac/NAS, and the pipeline does not download them again. State N and the reason. Do not delete files without the owner's yes.
3. At the end of a good `ops/pull-backup.sh`, run `ssh <vps> 'mkdir -p ~/.celibidache/offbox && touch ~/.celibidache/offbox/posturi.gov.ro.stamp'`.
4. Add rotation for `logs/alerts.log`.

## Done when

- The owner has a retention rule with numbers.
- `celi status` shows `backup:posturi` with an off-box age.

## Timezone change

On 2026-11-20 at 01:20 the system timezone changed from UTC to Europe/Bucharest. The lines of this project have one local slot each and ran correctly on 2026-11-20 (13:15).
