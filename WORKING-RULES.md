# Working Rules for AI Development Partners

**Version:** 1.0
**Last Updated:** 2025-01-08
**Purpose:** Prevent wasted time and poor code quality when working with AI assistants

---

## The Core Problem

AI assistants (like Claude) have **NO MEMORY** between sessions and are **TOO EAGER** to write code without fully understanding the existing codebase. This leads to:

- Duplicate implementations of existing features
- Breaking changes to working code
- Misleading variable names and technical debt
- Hours wasted troubleshooting preventable issues
- Code that doesn't follow project patterns

---

## Mandatory Session Startup (EVERY TIME)

Before any work begins, the AI assistant MUST:

1. ✅ Read `PROJECT-STATE.md` completely
2. ✅ Read `docs/DATA_STRUCTURE.md`
3. ✅ Read the last 3 entries in `CHANGELOG.md`
4. ✅ Summarize understanding of:
   - The data architecture (3-layer separation)
   - The venue_id vs pricing_map_id gotcha
   - Recent changes and their implications
5. ✅ Wait for human confirmation before proceeding

**Human's role:** Don't let the AI skip this. It seems like busywork, but it saves hours later.

---

## Rule 1: ALWAYS Research Before Coding

### ❌ WRONG Workflow
```
Human: "Add a function to get available seats"
AI: *immediately writes new function*
```

### ✅ CORRECT Workflow
```
Human: "Add a function to get available seats"
AI: "Let me search for existing patterns first..."
AI: *Uses Grep to search for "available seats", "get_seats", etc.*
AI: *Reads relevant files completely*
AI: "I found that HOPE_Pricing_Maps_Manager::get_seats_with_pricing()
     already does something similar. Should I:
     A) Use that existing method
     B) Extend it with new functionality
     C) Create a new method for a different use case"
Human: *Makes informed decision*
```

### Required Search Commands

Before writing ANY new function or feature:

```bash
# Search for similar functionality
Grep: pattern="function.*get.*seat" OR pattern="available.*seat"

# Search for existing variables/properties
Grep: pattern="venue_id|pricing_map"

# Find related files
Glob: pattern="**/class-*seat*.php"
```

**Don't assume.** Don't guess. **Search and read.**

---

## Rule 2: Explain Before Implementing

### The Proposal Template

When asked to build a feature, respond with this structure:

```markdown
## Understanding
[Explain what you understand the request to be]

## Research Findings
[What existing code/patterns you found]

## Proposed Approach
[How you plan to implement it]

### Files to Modify
- file1.php (lines X-Y): [what changes]
- file2.js (lines A-B): [what changes]

### New Files to Create
- file3.php: [purpose]

### Risks/Concerns
- [Potential breaking changes]
- [Dependencies that might be affected]
- [Technical debt being created]

## Alternatives Considered
- Option A: [pros/cons]
- Option B: [pros/cons]

**Waiting for approval before implementing.**
```

**Do not write code until the human approves your approach.**

---

## Rule 3: Never Trust Variable Names

This project has misleading variable names. Examples:

- `venue_id` actually contains pricing map ID
- `_hope_seating_venue_id` meta key stores pricing map ID
- Function `ajax_get_event_venue()` returns pricing map data

### Required Verification

When you see a variable, **always verify**:

```php
// ❌ Don't assume
$venue_id = get_post_meta($product_id, '_hope_seating_venue_id', true);
// AI assumes: "This gets a venue ID"

// ✅ Verify by reading code
// Read: class-frontend.php line 337
// Discover: This actually gets pricing_map_id
// Document: Comment this finding
```

**Trust code execution, not variable names.**

---

## Rule 4: Read Files Completely, Not Snippets

### ❌ WRONG Approach
```
AI: *Reads lines 100-150 of file*
AI: *Makes assumption about file purpose*
AI: *Writes code based on incomplete understanding*
```

### ✅ CORRECT Approach
```
AI: Read entire file (use offset/limit if needed)
AI: Understand overall structure and purpose
AI: Note any relevant patterns, helpers, or gotchas
AI: THEN propose changes
```

### For Large Files

If a file has 1000+ lines:

1. Read the beginning (structure, class definition)
2. Read the specific section you need to modify
3. Read related methods in that section
4. Search for any method calls that might be affected
5. Acknowledge what you haven't read

**Be explicit about what you don't know.**

---

## Rule 5: Propose Pseudocode First for Complex Features

For anything beyond simple bug fixes:

### Step 1: Pseudocode
```javascript
// Proposed logic for seat reassignment:
function reassignSeat(oldSeatId, newSeatId, orderId) {
    // 1. Validate new seat is available
    // 2. Check user permissions
    // 3. Update bookings table
    // 4. Update order metadata
    // 5. Trigger ticket regeneration
    // 6. Return success/failure
}
```

### Step 2: Get Approval

Human reviews pseudocode and says:
- "Yes, proceed"
- "No, you're missing X"
- "Change step 4 to do Y instead"

### Step 3: Implement

Now write the actual code, following the approved logic.

**Complex changes need design approval before implementation.**

---

## Rule 6: Flag Misleading Code

When you encounter confusing/misleading code:

### ❌ Don't Silently Work Around It
```
AI: *sees confusing variable name*
AI: *figures out what it really means*
AI: *writes code that works*
AI: *moves on*
```

### ✅ Flag It Explicitly
```
AI: "I found that variable 'venue_id' on line 337 actually contains
     the pricing map ID, not a venue ID. This is misleading.

     Options:
     A) Rename it (risky - could break dependencies)
     B) Add a comment explaining it
     C) Add to PROJECT-STATE.md as a known issue

     What would you like me to do?"
```

**Make technical debt visible, don't hide it.**

---

## Rule 7: Update Documentation As You Code

### ❌ WRONG Timeline
```
1. Write code
2. Test code
3. Commit code
4. (Maybe) Update docs later
```

### ✅ CORRECT Timeline
```
1. Research and propose approach
2. Get approval
3. Write code AND update docs simultaneously
4. Review both code and docs
5. Commit both together
```

### What Documentation to Update

When you modify code, check if these need updates:

- [ ] Inline comments in the modified function
- [ ] File-level docblock
- [ ] `docs/SEAT-REASSIGNMENT.md` (or relevant feature doc)
- [ ] `PROJECT-STATE.md` (if architecture changed)
- [ ] `CHANGELOG.md` (for user-facing changes)
- [ ] `README.md` (if setup/installation affected)

**Documentation is not optional. It's part of the feature.**

---

## Rule 8: Show Diffs Before Committing

Before any git commit, show:

```markdown
## Files Changed
- file1.php: 45 insertions, 12 deletions
- file2.js: 123 insertions, 5 deletions

## Summary of Changes
[What changed and why]

## Technical Debt Created
[Any shortcuts taken, cleanup needed]

## Testing Performed
[What was tested and results]

## Risks
[What could break, what wasn't tested]
```

**Let the human make final commit decision, not the AI.**

---

## Rule 9: Ask Questions, Don't Assume

### Good Questions to Ask

- "I see two similar functions X and Y. Which one should I use/modify?"
- "This variable is named 'venue_id' but seems to contain pricing map data. Is that correct?"
- "Should I create a new file or add to existing class-admin-selective-refunds.php?"
- "The existing pattern does X, but that seems inefficient. Should I follow the pattern or improve it?"
- "I don't fully understand how the FooEvents integration works. Should I research more or do you want to explain?"

### Bad Assumptions to Avoid

- "This must be the venue ID because the variable is called venue_id"
- "I'll just create a new function since I can't find the existing one quickly"
- "This pattern looks old, I'll use a better modern approach"
- "The comment says X, so that must be what the code does"

**When in doubt, ask. Don't guess.**

---

## Rule 10: Acknowledge Your Limitations

### Things AI Should Say More Often

✅ "I don't have full context on how this integrates with FooEvents"
✅ "I only read the first 200 lines of this file, there might be relevant code I haven't seen"
✅ "I'm not certain about the database schema for this table, let me check"
✅ "This seems to conflict with the pattern I found earlier, I need clarification"
✅ "I have no memory of our previous session, please remind me of the approach we decided"

### Things AI Should Say Less Often

❌ "I'll quickly add..."
❌ "This should work..."
❌ "I assume this variable is..."
❌ "Based on the name..."
❌ "Typically this would..."

**Be confident about what you know. Be explicit about what you don't.**

---

## Rule 11: No "Quick Fixes" in Unfamiliar Code

### Resist the Temptation

```
Human: "This function is throwing an error"
AI: ❌ "Let me add a try/catch to fix it"
AI: ✅ "Let me read the function to understand WHY it's erroring"
```

### The Right Approach

1. **Understand the error** (read error message, check logs)
2. **Read the failing code** (entire function, not just error line)
3. **Trace the data flow** (where does the data come from?)
4. **Identify root cause** (what assumption is wrong?)
5. **Propose fix** (address cause, not symptom)
6. **Get approval** before implementing

**Understand before fixing. Don't patch symptoms.**

---

## Rule 12: Test Your Assumptions with Code

### Don't Trust Your Mental Model

```php
// ❌ AI thinks: "This returns an array"
$seats = get_available_seats($event_id);
// Writes code assuming $seats is an array

// ✅ AI verifies: "Let me check what this actually returns"
// Reads function definition
// Sees it returns object, not array
// Writes correct code
```

### Verification Checklist

Before using a function/method:

- [ ] What does it actually return? (array, object, bool, null?)
- [ ] What parameters does it expect? (types, required vs optional)
- [ ] What side effects does it have? (database writes, hooks fired)
- [ ] What error conditions exist? (returns false, throws exception)

**Read the function signature and implementation. Don't guess.**

---

## Human's Responsibilities

To get the best results from AI assistance, the human developer should:

### 1. Enforce These Rules

Don't let the AI skip steps. If it starts coding without proposing first, stop it:

```
❌ "Here's the implementation..."
✅ "Stop. Show me your research and proposed approach first."
```

### 2. Maintain Project State

Keep `PROJECT-STATE.md` updated with:
- Recent architectural decisions
- New gotchas discovered
- Active development priorities
- Known issues and workarounds

### 3. Start Each Session Right

Begin with:
```
"Before we start:
1. Read PROJECT-STATE.md
2. Read WORKING-RULES.md
3. Read the last 3 CHANGELOG entries
4. Summarize your understanding"
```

### 4. Provide Context

When asking for a feature:
```
❌ "Add seat reassignment"

✅ "Add seat reassignment feature. Context:
   - It should work like seat blocking (same modal system)
   - It needs to integrate with FooEvents for ticket regeneration
   - It should only allow one seat selection
   - Related files: class-admin-selective-refunds.php, seat-map.js
   - Read those files first, then propose approach"
```

### 5. Review Thoroughly

Before accepting AI code:
- [ ] Check if it follows existing patterns
- [ ] Verify it doesn't duplicate existing functionality
- [ ] Look for technical debt or misleading names
- [ ] Test in local environment
- [ ] Review documentation updates

**The human is responsible for code quality, not the AI.**

---

## Emergency Stop Phrases

If the AI is going down the wrong path, use these:

### 🛑 "STOP. Read first."
Use when: AI is about to write code without research

### 🛑 "STOP. Explain your approach."
Use when: AI jumps straight to implementation

### 🛑 "STOP. That function already exists."
Use when: AI is creating duplicate functionality

### 🛑 "STOP. You're assuming that variable contains X."
Use when: AI is misinterpreting variable names

### 🛑 "STOP. Show me what you've read."
Use when: AI seems to be working from incomplete information

**Don't let the AI waste your time. Interrupt early.**

---

## Success Metrics

A successful AI collaboration session should have:

✅ Zero duplicate code created
✅ Comprehensive documentation updated
✅ No misleading variable names added
✅ Clear understanding of changes and risks
✅ Explicit acknowledgment of AI limitations
✅ Clean, tested code following existing patterns

**Quality over speed. Understanding over assumptions.**

---

## Example Session (The Right Way)

```
Human: "Add ability to reassign customer seats in WooCommerce orders"

AI: "Before I propose an approach, let me research the codebase:

     1. Reading PROJECT-STATE.md...
        - Noted: venue_id actually means pricing_map_id
        - Noted: Use HOPE_Pricing_Maps_Manager for seat data
        - Noted: HOPESeatMap class used for visual selection

     2. Searching for existing seat management code...
        - Found: class-admin-seat-blocking.php (similar use case)
        - Found: Modal system in class-modal-handler.php
        - Found: HOPESeatMap class in assets/js/seat-map.js

     3. Reading class-admin-selective-refunds.php...
        - Already handles selective refunds
        - Has meta box on order edit screen
        - Could extend this class

     Based on research, here's my proposed approach:

     [Detailed proposal following Rule 2 template]

     Should I proceed with this approach?"