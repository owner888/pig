<?php

declare(strict_types=1);

namespace Pig\Agent;

/**
 * A conversation entry the app invented — a notification, a status line, an artifact.
 *
 * Upstream widens its `Message` union through declaration merging, so `AgentMessage`
 * there means "an LLM message **or** something the app added". PHP cannot widen a union
 * from outside, and pig/ai must not depend on pig/agent-core, so the split runs the other
 * way: this marks only the app's own messages, and a conversation is typed
 * `list<Message|AgentMessage>`. Since arrays carry no element type at run time, that costs
 * nothing but a docblock.
 *
 * It falls out neatly for `convertToLlm`, whose whole job is deciding what each of these
 * becomes before the model sees it — and whose default is to drop them.
 */
interface AgentMessage
{
}
