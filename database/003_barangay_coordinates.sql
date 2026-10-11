-- =========================================================
-- 003 - BARANGAY COORDINATES
-- =========================================================
--
-- WHY
-- ---
-- `barangays` was seeded with names only, so every row had NULL coordinates.
-- That forced every endpoint to geocode the user's free-text address just to
-- get a point, and free text is a bad geocoding query - "Barangay I" resolved
-- to another barangay, another city, another country.
--
-- These coordinates make the barangay the source of truth for a record's
-- position. An address is now a label; an explicit map pin may still refine it.
--
-- WHERE THEY CAME FROM
-- --------------------
-- `database/fill_barangay_coordinates.php`, which geocodes each name against
-- OpenStreetMap (Nominatim) and falls back to Geoapify, accepting a result only
-- when it actually IS the barangay: the returned label must contain the name,
-- the feature must be a place and not a river/road/school, and the point must
-- fall inside Dagupan. Both providers silently fall back to the city centre
-- when they do not know a place, so unguarded geocoding silently assigns a
-- dozen barangays the same coordinate.
--
-- Generated output is reproducible; re-running the script on a different
-- geocoder may shift a centroid slightly. It never invents a value - a name it
-- cannot resolve is left NULL on purpose rather than guessed at.
--
-- Idempotent and additive: safe to run more than once, and safe on a database
-- where the rows already have coordinates.
--
--     "C:\xampp\mysql\bin\mysql.exe" -u root powerguide < database\003_barangay_coordinates.sql
--
-- -------------------------------------------------------------
-- STILL MISSING - 11 barangays, entered by hand from a map
-- -------------------------------------------------------------
-- Neither OSM nor Geoapify has a usable entry for these, and every fallback
-- they offered was the Dagupan city centre, which would pile unrelated reports
-- on one point. A barangay with NULL coordinates is REJECTED by the API until
-- it is filled in, so these need a value before residents there can file
-- reports:
--
--     UPDATE barangays SET latitude = <lat>, longitude = <lng>
--     WHERE barangay_name = 'Bacag';
--
--   Bacag
--   Balsadan
--   Barangay I
--   Barangay II
--   Barangay III
--   Barangay IV
--   Dapdap
--   Dumalneg
--   Poblacion Este
--   Sapanglang
--   Tippler
--
-- Verify what is left with:
--   SELECT barangay_name FROM barangays
--   WHERE latitude IS NULL OR longitude IS NULL;
-- =========================================================

UPDATE barangays SET latitude = 16.0321184, longitude = 120.343659 WHERE barangay_name = 'Bacayao Norte';
UPDATE barangays SET latitude = 16.0224982, longitude = 120.3460497 WHERE barangay_name = 'Bacayao Sur';
UPDATE barangays SET latitude = 16.0470283, longitude = 120.3653888 WHERE barangay_name = 'Bolosan';
UPDATE barangays SET latitude = 16.1019298, longitude = 120.3797034 WHERE barangay_name = 'Bonuan Binloc';
UPDATE barangays SET latitude = 16.0774984, longitude = 120.3580251 WHERE barangay_name = 'Bonuan Boquig';
UPDATE barangays SET latitude = 16.0753807, longitude = 120.3431786 WHERE barangay_name = 'Bonuan Gueset';
UPDATE barangays SET latitude = 16.0452879, longitude = 120.325569  WHERE barangay_name = 'Calmay';
-- Coarse (Geoapify city-adjacent centroid, ~5 dp). Worth refining by hand.
UPDATE barangays SET latitude = 16.05,       longitude = 120.31667   WHERE barangay_name = 'Carael';
UPDATE barangays SET latitude = 16.0306481, longitude = 120.3499153 WHERE barangay_name = 'Caranglaan';
UPDATE barangays SET latitude = 16.0439297, longitude = 120.3422628 WHERE barangay_name = 'Herrero';
UPDATE barangays SET latitude = 16.0216379, longitude = 120.3402722 WHERE barangay_name = 'Lasip Chico';
UPDATE barangays SET latitude = 16.0286206, longitude = 120.3432008 WHERE barangay_name = 'Lasip Grande';
UPDATE barangays SET latitude = 16.0548499, longitude = 120.3248795 WHERE barangay_name = 'Lomboy';
UPDATE barangays SET latitude = 16.0204862, longitude = 120.3226274 WHERE barangay_name = 'Lucao';
UPDATE barangays SET latitude = 16.0292908, longitude = 120.3351235 WHERE barangay_name = 'Malued';
UPDATE barangays SET latitude = 16.0534043, longitude = 120.3615684 WHERE barangay_name = 'Mamalingling';
UPDATE barangays SET latitude = 16.0388765, longitude = 120.3673354 WHERE barangay_name = 'Mangin';
UPDATE barangays SET latitude = 16.0418406, longitude = 120.346308  WHERE barangay_name = 'Mayombo';
UPDATE barangays SET latitude = 16.0461741, longitude = 120.3387457 WHERE barangay_name = 'Pantal';
-- OSM has no standalone "Poblacion"; the Dagupan City Hall area is the same
-- place, so it shares Poblacion Oeste's centroid.
UPDATE barangays SET latitude = 16.0432792, longitude = 120.3295612 WHERE barangay_name = 'Poblacion';
UPDATE barangays SET latitude = 16.0432792, longitude = 120.3295612 WHERE barangay_name = 'Poblacion Oeste';
UPDATE barangays SET latitude = 16.0589914, longitude = 120.3217036 WHERE barangay_name = 'Salapingao';
UPDATE barangays SET latitude = 16.0423396, longitude = 120.3714122 WHERE barangay_name = 'Salisay';
UPDATE barangays SET latitude = 16.046036,  longitude = 120.3563194 WHERE barangay_name = 'Tambac';
UPDATE barangays SET latitude = 16.0323968, longitude = 120.3302149 WHERE barangay_name = 'Tapuac';
UPDATE barangays SET latitude = 16.0320226, longitude = 120.3588773 WHERE barangay_name = 'Tebeng';