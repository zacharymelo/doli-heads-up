# User guide

## Sending a flag

There are two ways to open the popup:

- **Alt+click** (Option+click on a Mac) anything on any page. The click is caught before the browser acts on it, so links and buttons don't fire.
- Click the **flag icon** in the top bar, choose **Flag something on this page**, then click what you mean. This is *pick mode*; press Esc or the banner's **Cancel** button to cancel.

To close the popup, press Esc, click the **×** in its corner, or click **Cancel**. On a phone the popup fills the screen, and the back gesture closes it too.

### A to-do about something outside Dolibarr

For a phone call, an email that hasn't arrived yet, or anything else with nothing to click on, use **New to-do**. You can find it in three places:

- in the flag icon's menu in the top bar
- as a button on **My queue**
- under **Heads-up → New to-do** in the left menu, which opens My queue with the popup already open. On a phone you can bookmark it or add it to your home screen.

This opens a blank popup that isn't linked to any record or page. If setup turns on the Project field, you can still choose a project.

The popup has two tabs:

- **To-do**: assign it to one or more people, with a title and a description.
- **Report a problem**: the person set in setup is selected already, and the title is filled in from the box that was clicked, for example "Problem: Customers going quiet".

You don't have to explain where you were. Heads-up records the page, the title of the nearest box, and the text you clicked.

### Choosing people

Under **Assign to**, the popup shows a short list of quick choices that an admin sets up. Anyone else can be found by typing in the search box. An admin can also choose to show every user as a button.

### Which record it's about

A browser can't send "the record", only what it sees. In Dolibarr, a record's identity is in its links (`/projet/card.php?id=1`), so the popup does this:

1. It uses the link you clicked.
2. If you clicked plain text, for example a project's title in the project list, it uses the links in the same table row.

The server matches these links against Dolibarr's card and tab URLs. It covers projects, tasks, third parties, contacts, proposals, orders, invoices, shipments, receptions, supplier orders and invoices, products, events, interventions, contracts, tickets, MOs and BOMs. It also covers custom modules' `*_card.php?id=` pages. The server then loads the record to check that it exists. Before you send, the popup shows **Links to: …** so you can see which record it found.

The clicked record becomes the to-do's main link, so the to-do appears on that record's **Events** tab, along with its project and third party. The record whose card you were on fills in whatever is still empty. The note also says, for example, "Linked to: PJ2611-0042 … (project #42)".

On the event card, the context is clickable:

- **Page** opens the exact page the flag was sent from.
- **Linked to** opens the clicked record's card.

### Project (optional)

If an admin has turned on the **Project field**, the popup has a search box under the title:

- **Automatic** (the default): the to-do takes the project of the record it is about. Another module may also choose one.
- **Search**: type part of a project's ref or title and pick one of the open projects you can see. Enter picks the first result.
- **No project**: the last choice in the results.

The × on the chosen project takes you back to Automatic.

## Assignments

When someone adds you to a record, you get an **Assignment** to-do, for example "Project leader on PJ2611-0042 …". This happens when:

- you are added as an internal contact on a project, task, proposal, order or invoice (in any contact role), or
- you are made a third party's sales representative.

Assigning yourself creates nothing. An admin can switch assignments off.

## My queue

Open My queue from **Heads-up → My queue** in the left menu, or from the flag icon in the top bar. The icon's bubble shows how many to-dos you have that are still **To do**. It is hidden at zero and refreshes every minute.

| Tab | Shows |
|---|---|
| **For me** | Open flags, problem reports and assignments: who sent each one, the record, a link back to the exact page, and its status |
| **Assigned to me** | Read straight from the records, so leads that arrived without an assignment event still show (from email collectors or imports, for example): open leads and projects you're on, your open tasks, open proposals and orders you follow, and third parties you are the sales rep for |
| **Sent by me** | What you sent and where each one stands |
| **Completed** | Every to-do assigned to you that is done, most recently finished first, with when and by whom, a page at a time |
| **Everyone's open flags** | All open flags (needs the "See everyone's flags" permission) |

Below 1000px wide, each to-do is shown as a card, so the controls can be reached on a phone without scrolling sideways.

## To do, acknowledged, in progress, done

Each to-do is in one of four states. The state is kept in the event's own percent, so Dolibarr's agenda shows it too.

| State | Percent | Meaning |
|---|---|---|
| **To do** | 0% | New. Counted in the top bar bubble. |
| **Acknowledged** | 10% | Seen. |
| **In progress** | 50% (and any other percent from 1 to 99) | Being worked on. |
| **Done** | 100% | Finished. Moves to the Completed tab. |

You can change the state in three places:

- the **progress slider** on each My queue row. It stops at 0, 10, 25, 50, 75 and 100%, the state follows the stop, and the arrow keys move it one stop at a time.
- the **Status** picker on each My queue row.
- the **Status: …** button on the event card, next to Modify.

An admin chooses whether the slider, the picker or both are shown.

Each change adds a line to the event's description, for example "Acknowledged by Alex Martin, 11/11/2026 10:49 AM". This lets the sender see who saw it and when.

These people can change a to-do's state:

- the people it is assigned to
- its sender
- anyone who can see everyone's flags
- anyone allowed to edit every event

To reopen a finished to-do, move its slider or picker back on the Completed tab.

## Conversation

Every to-do has a **Conversation** tab on its event card, for questions and answers about it. The tab shows the number of comments. In My queue, each to-do has a link to it: **Comment**, or **2 comments** once there are some.

The tab shows the to-do first, then the comments, oldest first, with yours on the right. Write in the box at the bottom and press **Send**. Comments are plain text.

The people who can read and write there are:

- the people the to-do is assigned to
- its sender
- anyone who can see everyone's flags
- anyone allowed to read or edit every event

You can delete your own comments (the bin next to the date). An administrator can delete anyone's. Deleting the to-do deletes its conversation.

## Browser notifications

When a new flag, problem report or assignment reaches you, the browser shows a notification such as "Heads-up from Sam: …". Clicking it opens My queue.

When someone else comments on a to-do you sent or are assigned to, the notification reads "Heads-up: Sam commented". Clicking it opens that conversation.

The browser asks for permission the first time you open the flag menu or the popup. Each to-do is shown once across all your tabs. Background tabs keep checking only after you have allowed notifications.
