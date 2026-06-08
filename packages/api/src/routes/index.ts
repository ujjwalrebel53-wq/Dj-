import type { FastifyInstance } from "fastify";
import { z } from "zod";
import { indexWorkspace, searchWorkspace } from "../services/indexer.js";

export async function registerIndexRoutes(app: FastifyInstance): Promise<void> {
  app.post("/v1/index", async (request) => {
    const body = z
      .object({
        workspaceRoot: z.string(),
        paths: z.array(z.string()).optional(),
      })
      .parse(request.body);

    const count = await indexWorkspace(body.workspaceRoot, body.paths);
    return { indexedChunks: count };
  });

  app.post("/v1/search", async (request) => {
    const body = z
      .object({
        workspaceRoot: z.string(),
        query: z.string(),
        limit: z.number().optional(),
      })
      .parse(request.body);

    const results = await searchWorkspace(body.workspaceRoot, body.query, body.limit);
    return { results };
  });

  app.get("/health", async () => ({ ok: true }));
}
