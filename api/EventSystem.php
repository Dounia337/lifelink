<?php
// ============================================================
// PATTERN 3: OBSERVER — Event-driven Notification System
// ============================================================
// Cheat sheet: "When the Subject changes state, ALL registered
//   Observers are notified automatically."
//   Subject: addObserver(), notifyObservers()
//   Observer: implements Observer interface + update()
//   "The model (Subject) has NO knowledge of the observers"
//
// Problem it solves: notification SQL was hardcoded inside
// verifyBloodType() and runMatching(). Those functions "knew"
// about notifications. Now they just fire an event — the
// observers handle reactions completely independently.
// ============================================================


// OBSERVER INTERFACE — PHP equivalent of java.util.Observer
// Every concrete observer must implement update()
interface LifeLinkObserver {
    public function update(string $event, array $data): void;
}


// SUBJECT (OBSERVABLE) — holds registered observers and broadcasts to them
// PHP equivalent of java.util.Observable
// Cheat sheet: addObserver(), notifyObservers() (we combine setChanged too)
class EventSubject {
    private array $observers = [];  // keyed by event name

    // Register an observer for a specific event
    // Equivalent to: model.addObserver(view)
    public function addObserver(string $event, LifeLinkObserver $observer): void {
        $this->observers[$event][] = $observer;
    }

    // Broadcast to all observers registered for this event
    // Equivalent to: setChanged() + notifyObservers()
    public function notifyObservers(string $event, array $data): void {
        foreach ($this->observers[$event] ?? [] as $observer) {
            $observer->update($event, $data);  // calls update() on each registered observer
        }
    }
}


// ---------------------------------------------------------------
// CONCRETE OBSERVER 1 — reacts to the 'blood_type_verified' event
// ---------------------------------------------------------------
// Before: this SQL lived inside verifyBloodType() in donors.php,
//         coupling verification logic to notification logic.
// Now:    verifyBloodType() fires the event; this observer reacts.
class BloodTypeVerifiedObserver implements LifeLinkObserver {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function update(string $event, array $data): void {
        // Send a notification to the donor
        $this->db->prepare("
            INSERT INTO notifications (user_id, type, title, message)
            VALUES (?, 'verification', 'Blood Type Verified ✓', ?)
        ")->execute([
            $data['donor_id'],
            "Your blood type ({$data['blood_type']}) has been officially verified.",
        ]);

        // Mark the user as verified in the users table
        $this->db->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")
                 ->execute([$data['donor_id']]);
    }
}


// ---------------------------------------------------------------
// CONCRETE OBSERVER 2 — reacts to the 'blood_request_created' event
// ---------------------------------------------------------------
// Before: match insertion + donor notifications were inside
//         runMatching() in requests.php, mixed with the algorithm.
// Now:    runMatching() fires the event; this observer handles
//         writing donor_matches rows and sending notifications.
class DonorMatchNotificationObserver implements LifeLinkObserver {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function update(string $event, array $data): void {
        $request      = $data['request'];
        $scoredDonors = $data['donors'];

        $insertMatch = $this->db->prepare("
            INSERT IGNORE INTO donor_matches
                (request_id, donor_id, distance_km, match_score, status)
            VALUES (?, ?, ?, ?, 'notified')
        ");
        $insertNotif = $this->db->prepare("
            INSERT INTO notifications
                (user_id, type, title, message, related_request_id)
            VALUES (?, 'emergency_request', ?, ?, ?)
        ");

        foreach ($scoredDonors as $donor) {
            // Write the match record
            $insertMatch->execute([
                $request['id'],
                $donor['id'],
                $donor['distance_km'],
                $donor['match_score'],
            ]);

            // Notify the donor
            $urgency = strtoupper($request['urgency']);
            $insertNotif->execute([
                $donor['id'],
                "[$urgency] {$request['blood_type']} Blood Needed",
                "A {$request['blood_type']} donor is needed ~{$donor['distance_km']}km away. Please respond ASAP.",
                $request['id'],
            ]);
        }
    }
}
