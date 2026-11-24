import XCTest
@testable import LughaKit

final class PipelineTests: XCTestCase {
    private func hit(_ target: String) -> Hit {
        Hit(entryId: 1, packageId: "p", target: target, speaker: nil, category: "dialogues", score: 1, method: "exact", providerId: "package", machineGenerated: false)
    }

    func testShowsOnlyStableTextAndHidesAfterSilence() async {
        var lookups = 0
        let p = SubtitlePipeline(stableFrames: 2, hideAfter: 1.0) { text in
            lookups += 1
            return text.hasPrefix("Hello") ? self.hit("مرحبًا") : nil
        }
        let t0 = Date(timeIntervalSince1970: 1000)

        let first = await p.ingest(lines: ["Hello there"], at: t0)
        XCTAssertFalse(first)
        XCTAssertNil(p.current, "half-drawn text is not shown on the first frame")
        let second = await p.ingest(lines: ["Hello there"], at: t0.addingTimeInterval(0.12))
        XCTAssertTrue(second)
        XCTAssertEqual(p.current?.text, "مرحبًا")
        _ = await p.ingest(lines: ["Hello there"], at: t0.addingTimeInterval(0.25))
        XCTAssertEqual(lookups, 1, "the same line on later frames costs nothing")

        _ = await p.ingest(lines: [], at: t0.addingTimeInterval(0.6))
        XCTAssertNotNil(p.current, "short gaps keep the subtitle")
        let hidden = await p.ingest(lines: [], at: t0.addingTimeInterval(1.4))
        XCTAssertTrue(hidden)
        XCTAssertNil(p.current)
        XCTAssertEqual(p.history.count, 1)
    }

    func testUnknownTextHidesTheOldLine() async {
        let p = SubtitlePipeline(stableFrames: 1) { $0 == "Known" ? self.hit("معروف") : nil }
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Known"], at: t0)
        XCTAssertEqual(p.current?.text, "معروف")
        _ = await p.ingest(lines: ["Some HUD text"], at: t0.addingTimeInterval(0.1))
        XCTAssertNotNil(p.current, "one frame without the English is not enough")
        _ = await p.ingest(lines: ["Some HUD text!"], at: t0.addingTimeInterval(1.0))
        XCTAssertNil(p.current)
    }

    func testMenusAreLookedUpLineByLine() async {
        let known: [String: (Int64, String)] = ["Map": (10, "الخريطة"), "Settings": (11, "الإعدادات")]
        let p = SubtitlePipeline(stableFrames: 1) { text in
            known[text].map { Hit(entryId: $0.0, packageId: "p", target: $0.1, speaker: nil, category: "menus", score: 1, method: "exact", providerId: "package", machineGenerated: false) }
        }
        _ = await p.ingest(lines: ["Map", "Game", "Settings"])
        XCTAssertEqual(p.current?.text, "الخريطة  ·  الإعدادات")
        _ = await p.ingest(lines: ["Nothing", "Known"], at: Date().addingTimeInterval(5))
        _ = await p.ingest(lines: ["Nothing", "Known!"], at: Date().addingTimeInterval(6))
        XCTAssertNil(p.current, "gone once nothing known has held past removeAfter")
    }

    func testHidesWhenTheAreaStaysEmptyWithoutNewFrames() async {
        let p = SubtitlePipeline(stableFrames: 1, hideAfter: 1.0) { _ in self.hit("مرحبًا") }
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Hello"], at: t0)
        XCTAssertNotNil(p.current)
        // One empty frame, then the picture stops changing: no more frames arrive.
        _ = await p.ingest(lines: [], at: t0.addingTimeInterval(0.2))
        XCTAssertFalse(p.tick(at: t0.addingTimeInterval(0.8)), "not empty long enough yet")
        XCTAssertTrue(p.tick(at: t0.addingTimeInterval(1.3)))
        XCTAssertNil(p.current)
    }

    func testTickKeepsALineThatIsStillOnScreen() async {
        let p = SubtitlePipeline(stableFrames: 1, hideAfter: 1.0) { _ in self.hit("مرحبًا") }
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Hello"], at: t0)
        // The English stays and the picture is static: no frames, but nothing says it went away.
        XCTAssertFalse(p.tick(at: t0.addingTimeInterval(5)))
        XCTAssertNotNil(p.current)
    }

    func testNoisyUnknownTextStillReplacesTheOldLine() async {
        // Whole-window capture during play: HUD text changes every frame, none of it known.
        let p = SubtitlePipeline(stableFrames: 2) { $0 == "Map" ? self.hit("الخريطة") : nil }
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Map"], at: t0); _ = await p.ingest(lines: ["Map"], at: t0.addingTimeInterval(0.1))
        XCTAssertEqual(p.current?.text, "الخريطة")
        _ = await p.ingest(lines: ["TURN ON", "$104"], at: t0.addingTimeInterval(0.3))
        _ = await p.ingest(lines: ["TURN 0N", "$1O4"], at: t0.addingTimeInterval(1.2))
        XCTAssertNil(p.current, "the menu closed: the Arabic must go even though the noise never repeats")
    }

    func testItemsKeepWhereTheEnglishWas() async {
        let known: [String: (Int64, String)] = ["Map": (10, "الخريطة"), "Help": (11, "المساعدة")]
        let p = SubtitlePipeline(stableFrames: 1) { t in
            known[t].map { Hit(entryId: $0.0, packageId: "p", target: $0.1, speaker: nil, category: "menus", score: 1, method: "exact", providerId: "package", machineGenerated: false) }
        }
        let map = CGRect(x: 0.05, y: 0.30, width: 0.10, height: 0.03), help = CGRect(x: 0.05, y: 0.45, width: 0.10, height: 0.03)
        _ = await p.ingest(ocr: [OCRLine(text: "Map", box: map), OCRLine(text: "Game", box: .zero), OCRLine(text: "Help", box: help)])
        XCTAssertEqual(p.current?.items.map(\.box), [map, help])
        XCTAssertEqual(p.current?.items.map(\.text), ["الخريطة", "المساعدة"])
    }

    func testAMenuDoesNotBlinkWhenOneFrameMissesItems() async {
        let known: [String: (Int64, String)] = ["Map": (10, "الخريطة"), "Help": (11, "المساعدة")]
        let p = SubtitlePipeline(stableFrames: 1) { t in
            known[t].map { Hit(entryId: $0.0, packageId: "p", target: $0.1, speaker: nil, category: "menus", score: 1, method: "exact", providerId: "package", machineGenerated: false) }
        }
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Map", "Help"], at: t0)
        XCTAssertEqual(p.current?.items.count, 2)
        // One animated frame: OCR reads only "Map".
        _ = await p.ingest(lines: ["Map", "Hel"], at: t0.addingTimeInterval(0.15))
        XCTAssertEqual(p.current?.items.count, 2, "a one-frame miss must not drop المساعدة")
        _ = await p.ingest(lines: ["Map", "Help"], at: t0.addingTimeInterval(0.3))
        XCTAssertEqual(p.current?.items.count, 2)
    }

    func testAlternatingMenuReadsKeepEveryLabelSteady() async {
        let known: [String: (Int64, String)] = ["Map": (10, "الخريطة"), "Store": (11, "المتجر"), "Settings": (12, "الإعدادات")]
        let p = SubtitlePipeline(stableFrames: 2) { t in
            known[t].map { Hit(entryId: $0.0, packageId: "p", target: $0.1, speaker: nil, category: "menus", score: 1, method: "exact", providerId: "package", machineGenerated: false) }
        }
        let t0 = Date(timeIntervalSince1970: 1000)
        _ = await p.ingest(lines: ["Map", "Store", "Settings"], at: t0)
        _ = await p.ingest(lines: ["Map", "Store", "Settings"], at: t0.addingTimeInterval(0.1))
        let steady = p.current?.text
        XCTAssertEqual(p.current?.items.count, 3)
        // An animated background: each frame OCR misses a different item.
        var changes = 0
        for k in 0 ..< 12 {
            let frame = [["Map", "Settings"], ["Store", "Settings", "Map"], ["Map", "Store"], ["Settings", "Map", "Store"]][k % 4]
            if await p.ingest(lines: frame, at: t0.addingTimeInterval(0.2 + Double(k) * 0.12)) { changes += 1 }
        }
        XCTAssertEqual(changes, 0, "no label may blink while the menu is up")
        XCTAssertEqual(p.current?.text, steady)
    }

    // MARK: recorded frames (GTA VI through PS Remote Play)

    private func box(_ v: [Double]) -> CGRect { CGRect(x: v[0], y: v[1], width: v[2], height: v[3]) }

    private func lookupTable(_ table: [String: String]) -> (String) async -> Hit? {
        { q in
            let key = q.lowercased().trimmingCharacters(in: CharacterSet(charactersIn: " .!?"))
            guard let (n, t) = table.enumerated().first(where: { $0.element.key.lowercased().trimmingCharacters(in: CharacterSet(charactersIn: " .!?")) == key }).map({ ($0.offset, $0.element.value) }) else { return nil }
            return Hit(entryId: Int64(n + 1), packageId: "gta", target: t, speaker: nil, category: "dialogues", score: 1, method: "exact", providerId: "p", machineGenerated: false)
        }
    }

    func testAWrappedSubtitleWithSpeakerAndHudPromptsIsOneLine() async {
        var asked: [String] = []
        let table = lookupTable(["Alright. Gimme Brian's rent or I kick your ass and turn this place upside down.": "طيب. عطني إيجار برايان", "DEFUSE": "فكك"])
        let p = SubtitlePipeline(stableFrames: 1) { q in asked.append(q); return await table(q) }
        let frame = [
            OCRLine(text: "DEFUSE", box: box([0.946, 0.548, 0.042, 0.078])),
            OCRLine(text: "Jason: Alright. Gimme Brian's rent or I kick your ass and turn this", box: box([0.213, 0.533, 0.574, 0.143])),
            OCRLine(text: "THREATEN (", box: box([0.931, 0.724, 0.068, 0.085])),
            OCRLine(text: "place upside down.", box: box([0.415, 0.72, 0.173, 0.113])),
            OCRLine(text: "airnи(™", box: box([0.952, 0.933, 0.047, 0.063])),
        ]
        await p.ingest(ocr: frame)
        let texts = Set(p.current?.items.map(\.text) ?? [])
        XCTAssertEqual(texts, ["طيب. عطني إيجار برايان", "فكك"])
        XCTAssertEqual(p.current?.items.first(where: { $0.text.hasPrefix("طيب") })?.speaker, "Jason")
        XCTAssertFalse(asked.contains { $0.contains("airn") }, "OCR noise from the picture is not looked up")
        // The next frame with the same text costs no lookups.
        let before = asked.count
        await p.ingest(ocr: frame.reversed())
        XCTAssertEqual(asked.count, before)
    }

    func testPiecesOfOneRowAreJoined() async {
        let p = SubtitlePipeline(stableFrames: 1, lookup: lookupTable(["Pfft! I'll turn you upside down!": "هيه! بقلب عاليكم سافلكم!"]))
        await p.ingest(ocr: [
            OCRLine(text: "GRAB", box: box([0.467, 0.1, 0.031, 0.075])),
            OCRLine(text: "Clerk: Pfft! I'll", box: box([0.339, 0.708, 0.125, 0.117])),
            OCRLine(text: "turn you upside down!", box: box([0.461, 0.713, 0.202, 0.121])),
        ])
        XCTAssertEqual(p.current?.items.map(\.text), ["هيه! بقلب عاليكم سافلكم!"])
    }

    func testShortTextNeedsAnExactMatchAndSingleLettersAreIgnored() async {
        var asked: [String] = []
        let p = SubtitlePipeline(stableFrames: 1) { q in
            asked.append(q)
            return q == "COLLECTION" ? Hit(entryId: 9, packageId: "gta", target: "المجموعات", speaker: nil, category: "ui", score: 0.8, method: "fuzzy", providerId: "p", machineGenerated: false) : nil
        }
        await p.ingest(ocr: [OCRLine(text: "COLLECTION", box: box([0.6, 0.9, 0.2, 0.05])), OCRLine(text: "N", box: box([0.1, 0.1, 0.01, 0.03]))])
        XCTAssertNil(p.current, "a fuzzy hit on a sign word is not shown")
        XCTAssertFalse(asked.contains("N"))
    }

    func testSpeakerNames() {
        XCTAssertEqual(SubtitlePipeline.splitSpeaker("Clerk: Rent's just a construct.").speaker, "Clerk")
        XCTAssertEqual(SubtitlePipeline.splitSpeaker("Officer Diaz: Hands up!").text, "Hands up!")
        XCTAssertNil(SubtitlePipeline.splitSpeaker("Now for the second part: fuck off.").speaker)
    }

    func testTwoStackedSubtitlesAreMatchedAsTheirOwnRuns() async {
        // Recorded: a two-line subtitle with the next speaker's line right under it.
        let p = SubtitlePipeline(stableFrames: 1, lookup: lookupTable([
            "Hey.. police? I think somebody's here for my body parts. Lock'em up!": "هيه... الشرطة؟ أظن فيه أحد يبي يقطعني. اقبضوا عليه",
            "Dear God!": "يا ساتر!",
        ]))
        await p.ingest(ocr: [
            OCRLine(text: "Hey.. police? I think somebody's here for my body parts.", box: box([0.35, 0.822, 0.299, 0.026])),
            OCRLine(text: "Lock'em up!", box: box([0.464, 0.85, 0.07, 0.029])),
            OCRLine(text: "Dear God!", box: box([0.465, 0.893, 0.063, 0.026])),
        ])
        XCTAssertEqual(p.current?.items.map(\.text), ["هيه... الشرطة؟ أظن فيه أحد يبي يقطعني. اقبضوا عليه", "يا ساتر!"])
    }

    func testASignWordReadIntoTheSubtitleRowIsDropped() async {
        let p = SubtitlePipeline(stableFrames: 1, lookup: lookupTable(["Hold your horses! I'm going.": "اصبر شوي! أنا رايح."]))
        await p.ingest(ocr: [OCRLine(text: "Hold your horses! I'm going. LOEGER", box: box([0.35, 0.85, 0.3, 0.03]))])
        XCTAssertEqual(p.current?.items.map(\.text), ["اصبر شوي! أنا رايح."])
        XCTAssertEqual(SubtitlePipeline.withoutStrayWord("OK. GTA is here"), [], "a sentence-case line keeps an all-caps word that is not at an end")
        XCTAssertEqual(SubtitlePipeline.withoutStrayWord("SPEED LIMIT 40"), [], "an all-caps sign stays whole")
    }

    func testAHudWordOcrMissesForAMomentStaysUp() async {
        let p = SubtitlePipeline(stableFrames: 1, lookup: lookupTable(["THREATEN": "هدد", "Hold your horses! I'm going.": "اصبر شوي! أنا رايح."]))
        let t0 = Date(timeIntervalSince1970: 0)
        let hud = OCRLine(text: "THREATEN", box: box([0.93, 0.72, 0.06, 0.08]))
        let line = OCRLine(text: "Hold your horses! I'm going.", box: box([0.35, 0.85, 0.3, 0.03]))
        await p.ingest(ocr: [hud, line], at: t0)
        await p.ingest(ocr: [OCRLine(text: "Something else entirely", box: box([0.1, 0.1, 0.2, 0.03]))], at: t0.addingTimeInterval(0.2))
        p.tick(at: t0.addingTimeInterval(0.7))
        XCTAssertEqual(p.current?.items.map(\.text), ["هدد"], "the dialogue line goes on time, the HUD word waits")
        p.tick(at: t0.addingTimeInterval(1.1))
        XCTAssertNil(p.current)
    }

    func testTypographicPunctuationAndLowercaseTitlesFromTheLog() async {
        XCTAssertEqual(SubtitlePipeline.plain("CO Wilson: Okay…"), "CO Wilson: Okay...")
        XCTAssertEqual(SubtitlePipeline.plain("don’t “go”"), "don't \"go\"")
        XCTAssertEqual(SubtitlePipeline.splitSpeaker("co Wilson: keep it straight, don't fuck around.").speaker, "co Wilson")
        XCTAssertEqual(SubtitlePipeline.splitSpeaker("CO Wilson: Nah, nah, we're good.").text, "Nah, nah, we're good.")
        let p = SubtitlePipeline(stableFrames: 1, lookup: lookupTable(["Okay...": "تمام..."]))
        await p.ingest(ocr: [OCRLine(text: "CO Wilson: Okay…", box: box([0.4, 0.72, 0.2, 0.013]))])
        XCTAssertEqual(p.current?.items.first?.text, "تمام...")
        XCTAssertEqual(p.current?.items.first?.speaker, "CO Wilson")
    }

    func testAShortSpokenLineGoesOnTimeLikeOtherDialogue() async {
        let p = SubtitlePipeline(stableFrames: 1, lookup: lookupTable(["Yeah.": "إيه"]))
        let t0 = Date(timeIntervalSince1970: 0)
        await p.ingest(ocr: [OCRLine(text: "Lucia: Yeah.", box: box([0.464, 0.725, 0.071, 0.012]))], at: t0)
        await p.ingest(ocr: [OCRLine(text: "Unknown words here now", box: box([0.3, 0.725, 0.3, 0.012]))], at: t0.addingTimeInterval(0.1))
        p.tick(at: t0.addingTimeInterval(0.6))
        XCTAssertNil(p.current, "Lucia's 'Yeah.' is dialogue, not a flickering HUD word")
    }

    func testStableForDelaysUntilTheTextHeld() async {
        let p = SubtitlePipeline(stableFrames: 1, lookup: lookupTable(["Hold your horses! I'm going.": "اصبر شوي! أنا رايح."]))
        p.stableFor = 0.2
        let t0 = Date(timeIntervalSince1970: 0)
        let line = OCRLine(text: "Hold your horses! I'm going.", box: box([0.35, 0.85, 0.3, 0.03]))
        await p.ingest(ocr: [line], at: t0)
        XCTAssertNil(p.current)
        p.tick(at: t0.addingTimeInterval(0.25))
        XCTAssertEqual(p.current?.items.count, 1)
    }

    func testGarbledSpeakerNamesWithoutColonFromTheLog() async {
        let p = SubtitlePipeline(stableFrames: 1, lookup: lookupTable([
            "Uhm, you have something to say to us?": "عندك شي تقوله لضيوفنا؟",
            "Absolutely no rush you take your time": "على راحتك، خذ وقتك!",
        ]))
        // Recorded: the line moved up when the next speaker's line appeared under it; both names garbled, no colon.
        await p.ingest(ocr: [
            OCRLine(text: "burists, Uhrm", box: box([0.363, 0.703, 0.075, 0.012])),
            OCRLine(text: "you have something to say to us?", box: box([0.443, 0.703, 0.2, 0.012])),
            OCRLine(text: "Staf Gember Absolutely no rush you take your time", box: box([0.339, 0.725, 0.317, 0.012])),
        ])
        XCTAssertEqual(Set(p.current?.items.map(\.text) ?? []), ["على راحتك، خذ وقتك!"], "the first line's text lost its 'Uhm' to the garble")
        let q = SubtitlePipeline(stableFrames: 1, lookup: lookupTable(["You can't catch me!": "ما تقدر تمسكني!"]))
        await q.ingest(ocr: [OCRLine(text: "Stranger You can't catch me!", box: box([0.4, 0.72, 0.2, 0.012]))])
        XCTAssertEqual(q.current?.items.first?.text, "ما تقدر تمسكني!")
    }

    func testButtonIconsSplitPromptsAndCountersStay() async {
        XCTAssertEqual(SubtitlePipeline.glyphParts("DEFUSE X PROVOKE").map(\.text), ["DEFUSE", "PROVOKE"])
        XCTAssertEqual(SubtitlePipeline.trimGlyphs("[O] Photo Studio"), "Photo Studio")
        XCTAssertEqual(SubtitlePipeline.splitCounter("TAKE AMMO PACK (0/3)").1, "(0/3)")
        let p = SubtitlePipeline(stableFrames: 1, lookup: lookupTable(["DEFUSE": "فكك", "PROVOKE": "استفزاز", "TAKE AMMO PACK": "خذ صندوق ذخيرة"]))
        await p.ingest(ocr: [
            OCRLine(text: "DEFUSE X PROVOKE", box: box([0.8, 0.7, 0.16, 0.013])),
            OCRLine(text: "TAKE AMMO PACK (0/3)", box: box([0.1, 0.3, 0.15, 0.013])),
        ])
        let items = p.current?.items ?? []
        XCTAssertEqual(Set(items.map(\.text)), ["فكك", "استفزاز", "خذ صندوق ذخيرة (0/3)"])
        let defuse = items.first { $0.text == "فكك" }!, provoke = items.first { $0.text == "استفزاز" }!
        XCTAssertLessThan(defuse.box.maxX, provoke.box.minX + 0.01, "each prompt over its own part of the row")
    }
}
