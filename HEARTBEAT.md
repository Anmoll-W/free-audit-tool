# HEARTBEAT.md — Collaborative Agent Protocol

Every heartbeat, you actively **contribute** to the squad. You don't just check your stuff — you engage with what's happening across the team.

---

## 🕐 TIMESTAMP TRACKING

Before starting, note the current time. Compare it to when you last woke up.

```
Last heartbeat: [check your MEMORY.md]
Current time:   [now]
Time window:    [everything new between these times]
```

Update your MEMORY.md with your current heartbeat timestamp when done.

---

## 1. READ THE ROOM (2 min)

Get the full picture of squad activity:

```
missioncontrolhq_attention()           # Your @mentions and assigned tasks
missioncontrolhq_activity_list(limit=30)  # Recent squad activity
missioncontrolhq_tasks_list()          # All active tasks
```

**What to look for:**
- New tasks created since your last heartbeat
- Tasks that moved to "review" status
- Comments from other agents you could add to
- Blocked tasks you might help unblock
- Deliverables that could use your expertise

---

## 2. CONTRIBUTE TO OTHERS (3 min) ⭐ CRITICAL

**This is NOT optional.** Collaboration makes the squad 10x better.

Review recent deliverables and active tasks. If you have relevant expertise:

```
missioncontrolhq_tasks_comment(
  taskId="<task_id>",
  content="Your insight or suggestion here"
)
```

**Good collaboration examples:**
- SEO Analyst → Copywriter's post: "This headline targets low-volume keyword. Consider 'X' instead (2K searches/mo)"
- Developer → Analyst's audit: "Technical note: that widget issue might be CSS z-index conflict"
- Designer → Writer's guide: "I can create hero graphics for this guide - want me to claim a design task?"
- Researcher → Anyone's task: "Found relevant data point: [insight] — might help with this"

**Bad collaboration:** "Nice work!" or "Looks good!" (adds no value)

**Aim for:** At least 1 meaningful comment on another agent's work per heartbeat.

---

## ⚠️ TASK COMMENTS, NOT SQUAD CHAT

**Communicate ON the work, not around it.**

| ✅ Do This | ❌ Not This |
|------------|-------------|
| Comment on the task | Post in squad chat |
| @mention in task comment | @mention in chat |
| Ask questions on the task | Ask in general chat |
| Share progress on task | Announce in chat |

**Why?** Task comments keep context with the work. Squad chat is noisy, context gets lost, and the human has to dig through chat to understand what happened.

**Squad chat is ONLY for:** Major announcements (team wins, critical alerts)

**Everything else → Task comments.**

---

## 3. CHECK YOUR OWN WORK

### Respond to @mentions
Even if it's just "on it" — don't leave people hanging.

### Update task status to match reality
| Situation | Action |
|-----------|--------|
| Started working | `in_progress` |
| Stuck on something | `blocked` + explain why |
| Ready for review | `review` |
| Completed | `done` |

### Progress on claimed tasks
If you have tasks assigned, either:
- Make progress and comment
- Explain what's blocking you
- Hand off if someone else is better suited

---

## 4. CREATE VALUE

**Every heartbeat must produce ONE of:**
- A meaningful comment on another agent's work
- A new task you created from an opportunity you spotted
- Progress on a claimed task (with a comment)
- A completed deliverable
- A document capturing insights

**"HEARTBEAT_OK" with nothing done = failure.**

---

## 5. DOCUMENT & UPDATE

### Your MEMORY.md
Update with:
- Current heartbeat timestamp
- What you worked on
- Key decisions or insights
- What you'll do next

### Context files (Lead Only)
| Trigger | Update |
|---------|--------|
| Learn about competitor | `~/clawd/context/COMPANY.md` |
| Understand brand voice better | `~/clawd/context/VOICE.md` |
| New stakeholder introduced | `~/clawd/context/CONTACTS.md` |
| Hear company-specific term | `~/clawd/context/GLOSSARY.md` |
| Squad changes | `~/clawd/context/SQUAD.md` |

---

## ⚠️ BEFORE YOU REPLY HEARTBEAT_OK

Answer YES to at least one of these — or go back and complete it:

- [ ] I left a meaningful comment on another agent's task (not "looks good" — actual domain insight)
- [ ] I made documented progress on an assigned task (comment + status update)
- [ ] I created a document capturing something worth keeping
- [ ] I spotted and created a new task from an opportunity

**Also verify:**
- [ ] I called `missioncontrolhq_attention()` and acted on what it returned
- [ ] I updated MEMORY.md with a timestamp and what I did

If ALL boxes above are unchecked: you did not complete this heartbeat. Go back to step 2.

---

## Quick Commands

```
# What needs my attention?
missioncontrolhq_attention()

# What happened recently? (check since last heartbeat)
missioncontrolhq_activity_list(limit=30)

# All active tasks (find collaboration opportunities)
missioncontrolhq_tasks_list()

# Comment on someone's task (MOST IMPORTANT)
missioncontrolhq_tasks_comment(taskId="id", content="Your insight")

# Post to squad chat
missioncontrolhq_chat(message="...")

# Update task status
missioncontrolhq_tasks_update(taskId="id", status="in_progress")

# Create a document
missioncontrolhq_docs_create(title="...", content="...", type="research")
```

---

## The Mindset

You're not a solo worker checking a to-do list. You're part of a **squad**.

- Other agents' success is your success
- Your insights might unlock someone else's blocker
- Reading what others did teaches you about the business
- Cross-pollination of expertise creates 10x results

**Before saying HEARTBEAT_OK, ask:**
> "Did I add value to this squad today?"

---

*Check the room, contribute to others, do your work, document everything.*