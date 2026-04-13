import os

path = r"c:\xampp\htdocs\FYP\admin\shuttle_management\shuttles_management.php"
with open(path, "r", encoding="utf-8") as f:
    content = f.read()
    
target = """                                // Utilization Metric
                                $tripsToday = 0;
                                try {
                                    $agg = $firestore->database()->collection('Bookings')
                                        ->where('shuttle_id', '=', $doc->id())
                                        ->where('date', '=', $today)
                                        ->count()->get();
                                    foreach ($agg as $res) {
                                        $tripsToday = $res->get('count') ?? 0;
                                    }
                                } catch (Exception $e) {
                                    $tripsToday = "-";
                                }"""

repl = """                                // Utilization Metric
                                $tripsToday = 0;
                                try {
                                    $query = $firestore->database()->collection('Bookings')
                                        ->where('shuttle_id', '=', $doc->id())
                                        ->where('date', '=', $today);
                                    if (method_exists($query, 'count')) {
                                        $agg = $query->count();
                                        if (is_int($agg)) {
                                            $tripsToday = $agg;
                                        } elseif (is_object($agg) && method_exists($agg, 'get')) {
                                            $resArray = $agg->get();
                                            foreach ($resArray as $res) {
                                                if (is_object($res) && method_exists($res, 'get')) {
                                                    $tripsToday = $res->get('count') ?? 0;
                                                }
                                            }
                                        }
                                    } else {
                                        $tripsToday = "-";
                                    }
                                } catch (Exception $e) {
                                    $tripsToday = "-";
                                }"""

# Fix CRLF issues by testing both
content = content.replace(target.replace("\n", "\r\n"), repl)
content = content.replace(target, repl)

with open(path, "w", encoding="utf-8") as f:
    f.write(content)

print("Fixed shuttles_management.php")


# Fix index.js
path2 = r"c:\xampp\htdocs\FYP\functions\index.js"
with open(path2, "r", encoding="utf-8") as f:
    content2 = f.read()

target2 = """            const shuttlesQuery = await db.collection("Shuttles")
                .where("zone_id", "==", booking.zone_id)
                .where("job_status", "==", "Idle")
                .where("is_online", "==", true)
                .get();"""

repl2 = """            const shuttlesQuery = await db.collection("Shuttles")
                .where("zone_id", "==", booking.zone_id)
                .where("job_status", "==", "Idle")
                .where("is_online", "==", true)
                .where("status", "==", "active")
                .get();"""

content2 = content2.replace(target2.replace("\n", "\r\n"), repl2)
content2 = content2.replace(target2, repl2)

with open(path2, "w", encoding="utf-8") as f:
    f.write(content2)

print("Fixed index.js")
