import Fastify from "fastify";
import cors from "@fastify/cors";
import { config } from "./config.js";
import { registerChatRoutes } from "./routes/chat.js";
import { registerIndexRoutes } from "./routes/index.js";

const app = Fastify({ logger: true });

await app.register(cors, { origin: true });
await registerChatRoutes(app);
await registerIndexRoutes(app);

app.listen({ port: config.port, host: "0.0.0.0" }).catch((error) => {
  app.log.error(error);
  process.exit(1);
});
